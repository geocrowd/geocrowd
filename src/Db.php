<?php

namespace Geocrowd;

use PDO;

final class Db
{
    private const SCHEMA = <<<'SQL'
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY,
            email TEXT NOT NULL UNIQUE COLLATE NOCASE,
            name TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL CHECK (role IN ('admin', 'moderator')),
            created_at TEXT NOT NULL,
            last_login_at TEXT
        );
        CREATE TABLE IF NOT EXISTS invitations (
            id INTEGER PRIMARY KEY,
            token_hash TEXT NOT NULL UNIQUE,
            email TEXT NOT NULL COLLATE NOCASE,
            role TEXT NOT NULL CHECK (role IN ('admin', 'moderator')),
            created_at TEXT NOT NULL,
            expires_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS points (
            id INTEGER PRIMARY KEY,
            collection TEXT NOT NULL,
            lat REAL NOT NULL,
            lng REAL NOT NULL,
            properties TEXT NOT NULL,
            status TEXT NOT NULL CHECK (status IN ('pending', 'published', 'rejected')),
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS points_collection_status ON points (collection, status);
        CREATE INDEX IF NOT EXISTS points_position ON points (lat, lng);
        CREATE TABLE IF NOT EXISTS collections (
            id TEXT PRIMARY KEY,
            definition TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS edits (
            id INTEGER PRIMARY KEY,
            point_id INTEGER NOT NULL REFERENCES points (id) ON DELETE CASCADE,
            lat REAL,
            lng REAL,
            changes TEXT NOT NULL,
            comment TEXT,
            created_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS edits_point ON edits (point_id);
        CREATE TABLE IF NOT EXISTS recovery_codes (
            user_id INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
            code_hash TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS api_keys (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            key_hash TEXT NOT NULL UNIQUE,
            prefix TEXT NOT NULL,
            domains TEXT NOT NULL,
            created_at TEXT NOT NULL,
            last_used_at TEXT
        );
        CREATE TABLE IF NOT EXISTS audit (
            id INTEGER PRIMARY KEY,
            user_id INTEGER,
            user_name TEXT,
            action TEXT NOT NULL,
            target TEXT NOT NULL,
            details TEXT,
            created_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS audit_created ON audit (created_at);
        CREATE TABLE IF NOT EXISTS hits (
            bucket TEXT NOT NULL,
            key_hash TEXT NOT NULL,
            created_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS hits_bucket_key ON hits (bucket, key_hash);
        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        SQL;

    public static function connect(string $file): PDO
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0750, true);
        }
        $pdo = new PDO('sqlite:' . $file, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON;');
        $pdo->exec(self::SCHEMA);
        self::migrate($pdo);
        return $pdo;
    }

    /** Mises à jour du schéma des bases existantes. */
    private static function migrate(PDO $pdo): void
    {
        $columns = array_column($pdo->query('PRAGMA table_info(points)')->fetchAll(), 'name');
        if (!in_array('source', $columns, true)) {
            // Identifiant du point dans une source importée (ex. : osm:node/123).
            $pdo->exec('ALTER TABLE points ADD COLUMN source TEXT');
        }
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS points_source ON points (collection, source)');

        $columns = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
        if (!in_array('totp_secret', $columns, true)) {
            // Double authentification : secret TOTP et dernier pas de temps utilisé (contre le rejeu).
            $pdo->exec('ALTER TABLE users ADD COLUMN totp_secret TEXT');
            $pdo->exec('ALTER TABLE users ADD COLUMN totp_last_step INTEGER');
        }
        if (!in_array('two_factor_method', $columns, true)) {
            // Méthode de double authentification du membre : 'totp', 'email' ou NULL (désactivée).
            $pdo->exec('ALTER TABLE users ADD COLUMN two_factor_method TEXT');
            $pdo->exec("UPDATE users SET two_factor_method = 'totp' WHERE totp_secret IS NOT NULL");
        }

        // Remplacée par la table hits, commune à toutes les limites de débit.
        $pdo->exec('DROP TABLE IF EXISTS submissions');
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    /** Valeur persistante générée à la première lecture (ex. : sel de hachage). */
    public static function setting(PDO $pdo, string $key, callable $generate): string
    {
        $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        if ($value === false) {
            $value = $generate();
            $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)')->execute([$key, $value]);
        }
        return $value;
    }
}
