<?php declare(strict_types=1);

namespace Domain\Engineering\Reliability\Write\UseCase;

use PDO;
use InvalidArgumentException;
use Domain\Engineering\Reliability\Write\Logic\TbfRecomputer;

final class ImportWos
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @param array{reliability_code: string, wo_ids: int[], added_by: string, now: string} $data
     */
    public function execute(array $data): void
    {
        $reliabilityId = $this->resolveReliabilityId($data['reliability_code']);

        $this->db->prepare('DELETE FROM eng_reliability_wo WHERE reliability_id = :id')
            ->execute(['id' => $reliabilityId]);

        if ($data['wo_ids'] === []) {
            return;
        }

        $fetchWo = $this->db->prepare('
            SELECT
                wo_history.wo_id,
                wo_history.wo_name,
                wo_history.asset_id,
                wo_history.wo_creation_time,
                (
                    SELECT emr.last_cumul
                    FROM eng_ml_event eme
                    JOIN asset_list al ON al.asset_code = eme.asset_code
                    JOIN eng_ml_event_reading emr ON emr.event_id = eme.id
                    WHERE al.asset_id = wo_history.asset_id
                      AND eme.added_time <= wo_history.wo_creation_time
                    ORDER BY eme.added_time DESC
                    LIMIT 1
                ) AS counter_value
            FROM wo_history
            WHERE wo_history.wo_id = :wo_id
        ');

        $insert = $this->db->prepare('
            INSERT INTO eng_reliability_wo
                (reliability_id, wo_id, asset_id, event_type, wo_name,
                 wo_failure_time, source_counter_value, counter_value,
                 included, added_by, added_time)
            VALUES
                (:reliability_id, :wo_id, :asset_id, \'WO\', :wo_name,
                 :wo_failure_time, :source_counter_value, :counter_value,
                 1, :added_by, :added_time)
            ON DUPLICATE KEY UPDATE wo_id = wo_id
        ');

        foreach ($data['wo_ids'] as $woId) {
            $fetchWo->execute(['wo_id' => $woId]);

            /**
             * @var object{
             *  wo_id: int,
             *  wo_name: string,
             *  wo_creation_time: string,
             *  asset_id: int,
             *  counter_value: null | float,
             * } | false $wo */
            $wo = $fetchWo->fetch(PDO::FETCH_OBJ);

            if ($wo === false) {
                continue;
            }
        }
    }

    private function resolveReliabilityId(string $code): int
    {
        $stmt = $this->db->prepare('SELECT id FROM eng_reliability WHERE code = :code');
        $stmt->execute(['code' => $code]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            throw new InvalidArgumentException('Reliability not found: ' . $code);
        }

        return (int) $id;
    }
}
