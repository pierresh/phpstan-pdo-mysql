#!/usr/bin/env node

// Reads {"dialect": "<node-sql-parser database>", "queries": ["sql1", "sql2", ...]} as JSON
// from stdin, prints {"results": [{"errors": [{"message": "...", "line": <int|null>}]}, ...]}
// as JSON to stdout - one result per input query, same order.
// Always exits 0 - parse errors are reported as data, not as a process failure.
//
// Batched by design: the caller is expected to send every query for a whole
// PHP class/file in one call rather than spawning a process per query - see
// NodeSqlParserAdapter::validateBatch() on the PHP side.

const { Parser } = require('node-sql-parser');

function readStdin() {
    return new Promise((resolve, reject) => {
        let data = '';
        process.stdin.setEncoding('utf8');
        process.stdin.on('data', (chunk) => (data += chunk));
        process.stdin.on('end', () => resolve(data));
        process.stdin.on('error', reject);
    });
}

function printResults(results) {
    process.stdout.write(JSON.stringify({ results }));
}

function errorResult(message) {
    return { errors: [{ message, line: null }] };
}

readStdin()
    .then((raw) => {
        let payload;
        try {
            payload = JSON.parse(raw);
        } catch (parseError) {
            printResults([errorResult(`Invalid JSON payload: ${parseError.message}`)]);
            return;
        }

        const parser = new Parser();

        const results = payload.queries.map((sql) => {
            try {
                parser.astify(sql, { database: payload.dialect });
                return { errors: [] };
            } catch (sqlError) {
                return {
                    errors: [
                        {
                            message: sqlError.message,
                            line: sqlError.location && sqlError.location.start ? sqlError.location.start.line : null,
                        },
                    ],
                };
            }
        });

        printResults(results);
    })
    .catch((error) => {
        printResults([errorResult(`sql-lint failed: ${error.message}`)]);
    });
