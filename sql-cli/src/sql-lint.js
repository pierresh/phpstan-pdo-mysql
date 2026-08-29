#!/usr/bin/env node

// Reads {"dialect": "<node-sql-parser database>", "sql": "..."} as JSON from stdin,
// prints {"errors": [{"message": "...", "line": <int|null>}]} as JSON to stdout.
// Always exits 0 - parse errors are reported as data, not as a process failure.

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

function printResult(errors) {
    process.stdout.write(JSON.stringify({ errors }));
}

readStdin()
    .then((raw) => {
        let payload;
        try {
            payload = JSON.parse(raw);
        } catch (parseError) {
            printResult([{ message: `Invalid JSON payload: ${parseError.message}`, line: null }]);
            return;
        }

        const parser = new Parser();

        try {
            parser.astify(payload.sql, { database: payload.dialect });
            printResult([]);
        } catch (sqlError) {
            printResult([
                {
                    message: sqlError.message,
                    line: sqlError.location && sqlError.location.start ? sqlError.location.start.line : null,
                },
            ]);
        }
    })
    .catch((error) => {
        printResult([{ message: `sql-lint failed: ${error.message}`, line: null }]);
    });
