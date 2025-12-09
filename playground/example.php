<?php

namespace Domain\Admin\Workflow\UseCase;

use PDO;
use PDOStatement;
use Exception;

use Domain\UseCase;
use Domain\UseCaseInterface;

/**
 * @phpstan-type DataInfo array{
 *  page_id: int,
 *  record_id: int,
 *  added_by_id: int,
 * }
 *
 * @phpstan-type Worfklow object{
 *  page_id: int,
 *  record_id: int,
 *  status: int,
 * }
 */

class DeleteWorkflow
{
    private readonly PDOStatement $load;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @param DataInfo $data */
    public function loadWorkflow($data): object
    {
        $load = $this->db->prepare("
            SELECT *
            FROM orders
            WHERE id = :id
            AND name = :name
            AND level = :level
        ");

        $load->execute(['id' => 1, 'adede' => 1]);

        $a = $load->rowCount();

        if ($a === 0) {
            $msg = 'workflow not found, page_id: ' . $data['page_id']. ', record_id: ' . $data['record_id'];
            throw new Exception($msg);
        }

        /** @var Worfklow */
        return $this->load->fetch();
    }
}
