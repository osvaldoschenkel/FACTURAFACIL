<?php
declare(strict_types=1);

namespace FacturaFacil;

use PDO;
use RuntimeException;

final class Database
{
    public static function connect(string $storagePath): PDO
    {
        if (!is_dir($storagePath) && !mkdir($storagePath, 0700, true) && !is_dir($storagePath)) {
            throw new RuntimeException('No se pudo crear el directorio privado de datos.');
        }
        $pdo = new PDO('sqlite:' . rtrim($storagePath, '/') . '/facturafacil.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        @chmod(rtrim($storagePath, '/') . '/facturafacil.sqlite', 0600);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 15000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 email TEXT NOT NULL COLLATE NOCASE UNIQUE,
 password_hash TEXT NOT NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS products (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 code TEXT NOT NULL COLLATE NOCASE UNIQUE,
 name TEXT NOT NULL,
 description TEXT NOT NULL DEFAULT '',
 price_cents INTEGER NOT NULL CHECK(price_cents >= 0),
 vat_rate TEXT NOT NULL CHECK(vat_rate IN ('10.5','21')),
 active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS customers (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 name TEXT NOT NULL,
 document_type TEXT NOT NULL CHECK(document_type IN ('CUIT','CUIL','DNI','CF')),
 document_number TEXT NOT NULL,
 tax_condition INTEGER NOT NULL,
 address TEXT NOT NULL DEFAULT '',
 city TEXT NOT NULL DEFAULT '',
 province TEXT NOT NULL DEFAULT '',
 postal_code TEXT NOT NULL DEFAULT '',
 email TEXT NOT NULL DEFAULT '',
 phone TEXT NOT NULL DEFAULT '',
 active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1)),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(document_type,document_number)
);
CREATE TABLE IF NOT EXISTS settings (
 key TEXT PRIMARY KEY,
 value TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS invoices (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 customer_id INTEGER NOT NULL REFERENCES customers(id),
 customer_json TEXT NOT NULL,
 issuer_json TEXT NOT NULL,
 items_json TEXT NOT NULL,
 totals_json TEXT NOT NULL,
 issue_date TEXT NOT NULL,
 voucher_type INTEGER NOT NULL CHECK(voucher_type IN (1,6,11)),
 point_of_sale INTEGER NOT NULL CHECK(point_of_sale BETWEEN 1 AND 99998),
 environment TEXT NOT NULL CHECK(environment IN ('homologation','production')),
 status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','processing','authorized','rejected','uncertain')),
 number INTEGER,
 cae TEXT,
 cae_due_date TEXT,
 error TEXT,
 observations_json TEXT NOT NULL DEFAULT '[]',
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX IF NOT EXISTS invoices_number ON invoices(environment,point_of_sale,voucher_type,number) WHERE number IS NOT NULL AND status IN ('processing','authorized','uncertain');
CREATE INDEX IF NOT EXISTS invoices_status ON invoices(status,created_at);
CREATE TABLE IF NOT EXISTS invoice_events (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 invoice_id INTEGER NOT NULL REFERENCES invoices(id),
 event TEXT NOT NULL,
 detail TEXT NOT NULL DEFAULT '',
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS login_attempts (
 ip TEXT PRIMARY KEY,
 attempts INTEGER NOT NULL DEFAULT 0,
 window_start INTEGER NOT NULL
);
PRAGMA user_version = 1;
SQL);
        return $pdo;
    }
}
