<?php

namespace Helix\Database\Migrations;

use Helix\Database\Contracts\ConnectionInterface;
use RuntimeException;
use Throwable;

class Migrator {
    private const MIGRATION_TABLE = 'migrations';
    private const MIGRATION_FILE_PATTERN = '/*.php';

    public function __construct(
        private ConnectionInterface $connection,
        private string $migrationsPath,
        private bool $useTransactions = true,
        private string $migrationTable = self::MIGRATION_TABLE
    ) {}

    public function run(): void {
        $this->ensureMigrationsTable();
        
        $pendingMigrations = $this->getPendingMigrations();
        
        if (empty($pendingMigrations)) {
            return;
        }
        
        $batch = $this->getNextBatchNumber();
        
        foreach ($pendingMigrations as $migration) {
            $this->runMigration($migration, $batch);
        }
    }

    public function rollback(int $batches = 1): void {
        $migrations = $this->getMigrationsForRollback($batches);
        
        foreach (array_reverse($migrations) as $migration) {
            $this->runMigrationDown($migration);
        }
    }

    private function runMigration(array $migration, int $batch): void {
        $this->validateMigrationFile($migration);
        
        try {
            if ($this->useTransactions) {
                $this->connection->beginTransaction();
            }
            
            $instance = $this->instantiateMigration($migration['class']);
            $instance->up($this->connection);
            $this->recordMigration($migration, $batch);
            
            if ($this->useTransactions) {
                $this->connection->commit();
            }
        } catch (Throwable $e) {
            if ($this->useTransactions) {
                $this->connection->rollBack();
            }
            
            throw new RuntimeException(
                "Migration {$migration['id']} failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    private function runMigrationDown(array $migration): void {
        $this->validateMigrationFile($migration);
        
        try {
            if ($this->useTransactions) {
                $this->connection->beginTransaction();
            }
            
            $instance = $this->instantiateMigration($migration['class']);
            $instance->down($this->connection);
            $this->removeMigrationRecord($migration['id']);
            
            if ($this->useTransactions) {
                $this->connection->commit();
            }
        } catch (Throwable $e) {
            if ($this->useTransactions) {
                $this->connection->rollBack();
            }
            
            throw new RuntimeException(
                "Rollback of migration {$migration['id']} failed: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    private function ensureMigrationsTable(): void {
        $this->connection->query("
            CREATE TABLE IF NOT EXISTS {$this->migrationTable} (
                id VARCHAR(255) PRIMARY KEY,
                migration VARCHAR(255) NOT NULL,
                batch INTEGER NOT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                execution_time FLOAT NULL
            )
        ")->execute();
    }

    private function getPendingMigrations(): array {
        $files = $this->getMigrationFiles();
        $executed = $this->getExecutedMigrationIds();
        
        return array_filter($files, fn($file) => !in_array($file['id'], $executed));
    }

    private function getMigrationFiles(): array {
        $files = glob($this->migrationsPath . self::MIGRATION_FILE_PATTERN);
        
        return array_map(function ($file) {
            $id = basename($file, '.php');
            return [
                'id' => $id,
                'class' => $this->getMigrationClass($file),
                'file' => $file
            ];
        }, $files);
    }

    private function getMigrationClass(string $file): string {
        $contents = file_get_contents($file);
        
        if (!preg_match('/\bclass\s+(\w+)\b/', $contents, $matches)) {
            throw new RuntimeException("No class found in migration file: {$file}");
        }
        
        return $matches[1];
    }

    private function getExecutedMigrationIds(): array {
        $result = $this->connection->query(
            "SELECT id FROM {$this->migrationTable} ORDER BY executed_at"
        )->fetchAll();
        
        return array_column($result, 'id');
    }

    private function getNextBatchNumber(): int {
        $result = $this->connection->query(
            "SELECT COALESCE(MAX(batch), 0) + 1 as next_batch FROM {$this->migrationTable}"
        )->fetch();
        
        return (int) $result['next_batch'];
    }

    private function getMigrationsForRollback(int $batches = 1): array {
        $result = $this->connection->query(
            "SELECT id, migration as class FROM {$this->migrationTable}
            WHERE batch > (SELECT MAX(batch) FROM {$this->migrationTable}) - :batches
            ORDER BY executed_at DESC",
            ['batches' => $batches]
        )->fetchAll();
        
        return array_map(function ($row) {
            return [
                'id' => $row['id'],
                'class' => $row['class']
            ];
        }, $result);
    }

    private function validateMigrationFile(array $migration): void {
        if (!file_exists($migration['file'])) {
            throw new RuntimeException("Migration file not found: {$migration['file']}");
        }
        
        require_once $migration['file'];
        
        if (!class_exists($migration['class'])) {
            throw new RuntimeException("Migration class {$migration['class']} not found");
        }
    }

    private function instantiateMigration(string $class): object {
        $instance = new $class();
        
        if (!method_exists($instance, 'up') || !method_exists($instance, 'down')) {
            throw new RuntimeException("Migration class must implement both up() and down() methods");
        }
        
        return $instance;
    }

    private function recordMigration(array $migration, int $batch): void {
        $start = microtime(true);
        
        $this->connection->query(
            "INSERT INTO {$this->migrationTable} (id, migration, batch, execution_time) 
            VALUES (:id, :migration, :batch, :execution_time)",
            [
                'id' => $migration['id'],
                'migration' => $migration['class'],
                'batch' => $batch,
                'execution_time' => microtime(true) - $start
            ]
        )->execute();
    }

    private function removeMigrationRecord(string $id): void {
        $this->connection->query(
            "DELETE FROM {$this->migrationTable} WHERE id = :id",
            ['id' => $id]
        )->execute();
    }
}