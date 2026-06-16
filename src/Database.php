<?php
namespace CompanyFinder;

use PDO;

/**
 * Thin SQLite wrapper. Owns the connection and the schema migration so the
 * rest of the app can assume the tables exist.
 */
class Database
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA journal_mode = WAL;');
        $this->migrate();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS listings (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                source        TEXT NOT NULL,
                external_id   TEXT NOT NULL,
                title         TEXT NOT NULL,
                url           TEXT NOT NULL,
                description   TEXT,
                business_type TEXT,
                location      TEXT,
                state         TEXT,
                price         REAL,
                cash_flow     REAL,
                gross_revenue REAL,
                latitude      REAL,
                longitude     REAL,
                distance_mi   REAL,
                is_sample     INTEGER NOT NULL DEFAULT 0,
                scraped_at    TEXT NOT NULL,
                first_seen      TEXT,
                last_seen       TEXT,
                previous_price  REAL,
                price_changed_at TEXT,
                tags            TEXT,
                is_starred      INTEGER NOT NULL DEFAULT 0,
                UNIQUE(source, external_id)
            );
        SQL);

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_listings_type  ON listings(business_type);');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_listings_price ON listings(price);');

        // Per-observation history so price (and other figures) can be tracked
        // over time. A row is written on first sight and whenever price changes.
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS listing_history (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                source        TEXT NOT NULL,
                external_id   TEXT NOT NULL,
                price         REAL,
                cash_flow     REAL,
                gross_revenue REAL,
                recorded_at   TEXT NOT NULL
            );
        SQL);
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_history_listing ON listing_history(source, external_id);');

        // Add the tracking columns to databases created before this migration.
        foreach ([
            'first_seen'       => 'TEXT',
            'last_seen'        => 'TEXT',
            'previous_price'   => 'REAL',
            'price_changed_at' => 'TEXT',
            'tags'             => 'TEXT',
            'is_starred'       => 'INTEGER NOT NULL DEFAULT 0',
        ] as $col => $type) {
            $this->addColumnIfMissing('listings', $col, $type);
        }
    }

    private function addColumnIfMissing(string $table, string $column, string $type): void
    {
        $cols = $this->pdo->query("PRAGMA table_info($table)")->fetchAll();
        foreach ($cols as $c) {
            if (($c['name'] ?? '') === $column) {
                return;
            }
        }
        $this->pdo->exec("ALTER TABLE $table ADD COLUMN $column $type");
    }
}
