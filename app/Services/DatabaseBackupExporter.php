<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class DatabaseBackupExporter
{
    /**
     * Run a logical SQL dump to a file path. Returns the absolute path.
     *
     * @throws \RuntimeException
     */
    public function dumpToFile(string $targetPath): string
    {
        $dir = dirname($targetPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $driver = config('database.default');
        $connection = config("database.connections.{$driver}");

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $this->mysqlDump($connection, $targetPath);

            return $targetPath;
        }

        if ($driver === 'sqlite') {
            $this->sqliteDump($connection, $targetPath);

            return $targetPath;
        }

        throw new \RuntimeException('Database backup is only supported for MySQL/MariaDB and SQLite connections.');
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function mysqlDump(array $connection, string $targetPath): void
    {
        $host = (string) ($connection['host'] ?? '127.0.0.1');
        $port = (string) ($connection['port'] ?? '3306');
        $user = (string) ($connection['username'] ?? 'root');
        $password = (string) ($connection['password'] ?? '');
        $database = (string) ($connection['database'] ?? '');
        $socket = (string) ($connection['unix_socket'] ?? '');

        if ($database === '') {
            throw new \RuntimeException('Database name is not configured.');
        }

        $binary = trim((string) config('haztrack.mysqldump_path', ''));
        $tryCli = $binary !== '' && filter_var(env('MYSQLDUMP_USE_CLI', true), FILTER_VALIDATE_BOOLEAN);

        if ($tryCli) {
            $defaultsPath = $this->writeMysqlClientDefaultsFile($user, $password, $host, $port, $socket);
            try {
                $extraArgs = $this->buildMysqldumpExtraArgs();
                $this->runMysqlDumpWithOptionalRetry(
                    $binary,
                    $defaultsPath,
                    $database,
                    $targetPath,
                    $extraArgs
                );

                return;
            } catch (\Throwable $e) {
                logger()->warning('mysqldump CLI failed; using PHP SQL dump.', [
                    'exception' => $e->getMessage(),
                ]);
            } finally {
                if (isset($defaultsPath) && is_file($defaultsPath)) {
                    @unlink($defaultsPath);
                }
            }
        }

        (new MysqlDumpViaPhp)->dumpToFile($targetPath);
    }

    /**
     * MariaDB 10.5+ → MySQL 8 dumps sometimes need --column-statistics=0. Many Windows installs ship an
     * older mysqldump that does not support this flag (exit 1, no stderr). Default is off; enable with
     * MYSQLDUMP_COLUMN_STATISTICS_OFF=true in .env when needed.
     *
     * @return list<string>
     */
    private function buildMysqldumpExtraArgs(): array
    {
        $fromConfig = $this->extraMysqldumpArgsFromConfig();
        if (filter_var(env('MYSQLDUMP_COLUMN_STATISTICS_OFF', false), FILTER_VALIDATE_BOOLEAN)) {
            return array_values(array_unique(array_merge(['--column-statistics=0'], $fromConfig)));
        }

        return $fromConfig;
    }

    /**
     * @param  list<string>  $extraArgs
     */
    private function runMysqlDumpWithOptionalRetry(
        string $binary,
        string $defaultsPath,
        string $database,
        string $targetPath,
        array $extraArgs,
    ): void {
        try {
            $this->runMysqlDumpOnce($binary, $defaultsPath, $database, $targetPath, $extraArgs);
        } catch (\RuntimeException $first) {
            if (! $this->shouldRetryMysqldumpWithoutColumnStatistics($first, $extraArgs)) {
                throw $first;
            }

            $stripped = array_values(array_filter(
                $extraArgs,
                static fn (string $a) => $a !== '--column-statistics=0'
            ));
            $this->runMysqlDumpOnce($binary, $defaultsPath, $database, $targetPath, $stripped);

            logger()->info('mysqldump succeeded after retry without --column-statistics=0.');
        }
    }

    private function shouldRetryMysqldumpWithoutColumnStatistics(\RuntimeException $e, array $extraArgs): bool
    {
        if (! in_array('--column-statistics=0', $extraArgs, true)) {
            return false;
        }

        $msg = strtolower($e->getMessage());

        return str_contains($msg, 'unknown option')
            || str_contains($msg, 'unrecognized')
            || str_contains($msg, 'column_statistics')
            || str_contains($msg, 'exit code 1')
            || str_contains($msg, 'no error text');
    }

    /**
     * @param  list<string>  $extraArgs
     */
    private function runMysqlDumpOnce(
        string $binary,
        string $defaultsPath,
        string $database,
        string $targetPath,
        array $extraArgs,
    ): void {
        $args = array_merge(
            [
                $binary,
                '--defaults-extra-file='.$defaultsPath,
                '--single-transaction',
                '--quick',
            ],
            $extraArgs,
            [$database],
        );

        $timeout = (int) config('haztrack.backup_timeout_seconds', 3600);
        $process = new Process($args);
        $process->setTimeout($timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            if ($this->shouldRetryMysqlDumpViaWindowsCmd($process)) {
                $this->runMysqlDumpViaWindowsCmd($args, $targetPath, $timeout, $binary);

                return;
            }

            throw $this->mysqlDumpFailedException($process, $binary);
        }

        $out = $process->getOutput();
        if ($out === '' || $out === false) {
            throw new \RuntimeException(
                'mysqldump produced no output. Check database credentials and that the mysqldump version matches your MySQL server.'
            );
        }

        file_put_contents($targetPath, $out);
    }

    private function shouldRetryMysqlDumpViaWindowsCmd(Process $process): bool
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }
        if (! filter_var(env('MYSQLDUMP_WINDOWS_CMD_FALLBACK', true), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }
        if ($process->isSuccessful()) {
            return false;
        }

        $combined = trim($process->getErrorOutput()."\n".$process->getOutput());

        return $combined === '';
    }

    /**
     * Some Windows PHP/SAPI combinations do not surface mysqldump stderr to Symfony Process; running the
     * same argv through cmd with 2>&1 merges streams so we get a real error or a successful dump.
     *
     * @param  list<string>  $args
     */
    private function runMysqlDumpViaWindowsCmd(array $args, string $targetPath, int $timeout, string $binary): void
    {
        $probe = new Process($args);
        $cmdline = $probe->getCommandLine().' 2>&1';
        $shell = Process::fromShellCommandline($cmdline);
        $shell->setTimeout($timeout);
        $shell->run();

        $merged = $shell->getOutput();

        if (! $shell->isSuccessful()) {
            $msg = trim($merged !== '' ? $merged : 'mysqldump failed.');
            $code = $shell->getExitCode();

            throw new \RuntimeException(
                $msg.' (exit '.$code.')'
                    .($binary !== 'mysqldump' ? ' — set MYSQLDUMP_PATH to your mysqldump.exe if needed.' : '')
            );
        }

        if (trim($merged) === '') {
            throw new \RuntimeException(
                'mysqldump returned no output after Windows shell fallback. '
                .'Check DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD, and that the MySQL service is running.'
            );
        }

        file_put_contents($targetPath, $merged);
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function sqliteDump(array $connection, string $targetPath): void
    {
        $database = (string) ($connection['database'] ?? '');
        if ($database === '' || ! is_readable($database)) {
            throw new \RuntimeException('SQLite database file is not readable.');
        }

        $binary = (string) config('haztrack.sqlite3_path', 'sqlite3');

        $process = new Process([$binary, $database, '.dump']);
        $process->setTimeout((int) config('haztrack.backup_timeout_seconds', 3600));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                trim($process->getErrorOutput() ?: $process->getOutput())
                ?: 'sqlite3 .dump failed. Install sqlite3 or use MySQL for SQL backups.'
            );
        }

        file_put_contents($targetPath, $process->getOutput());
    }

    public function captureDumpToString(): string
    {
        $tmp = storage_path('app'.DIRECTORY_SEPARATOR.'backup'.DIRECTORY_SEPARATOR.'_tmp_'.uniqid('', true).'.sql');
        $this->dumpToFile($tmp);
        try {
            return (string) file_get_contents($tmp);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * MySQL option file format — avoids Windows/cmd issues with empty passwords and special characters.
     *
     * @see https://dev.mysql.com/doc/refman/8.0/en/option-files.html
     */
    private function writeMysqlClientDefaultsFile(
        string $user,
        string $password,
        string $host,
        string $port,
        string $socket,
    ): string {
        $dir = storage_path('app/backup');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = $dir.DIRECTORY_SEPARATOR.'mysqldump_cnf_'.uniqid('', true).'.cnf';

        $lines = [
            '[client]',
            'user='.$this->escapeMysqlOptionFileValue($user),
            'password='.$this->escapeMysqlOptionFileValue($password),
        ];

        if ($socket !== '') {
            $lines[] = 'socket='.$this->escapeMysqlOptionFileValue($socket);
        } else {
            $lines[] = 'host='.$this->escapeMysqlOptionFileValue($host);
            $lines[] = 'port='.$this->escapeMysqlOptionFileValue($port);
        }

        foreach ($this->mysqlSslOptionLines($host) as $line) {
            $lines[] = $line;
        }

        file_put_contents($path, implode("\n", $lines)."\n");
        @chmod($path, 0600);

        return $path;
    }

    private function escapeMysqlOptionFileValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z0-9_.:@\\/+-]+$/', $value)) {
            return $value;
        }

        return '"'.addcslashes($value, "\\\"\n\r\t").'"';
    }

    /** @return list<string> */
    private function mysqlSslOptionLines(string $host): array
    {
        $mode = trim((string) env('MYSQLDUMP_SSL_MODE', ''));
        if ($mode === 'OFF' || $mode === 'DISABLED') {
            return [];
        }

        $sslFlag = filter_var(env('MYSQL_SSL', null), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($sslFlag === null) {
            $sslFlag = str_contains($host, 'digitalocean');
        }

        if (! $sslFlag) {
            return [];
        }

        $lines = [];
        if ($mode !== '') {
            $lines[] = 'ssl-mode='.$this->escapeMysqlOptionFileValue($mode);
        } else {
            $lines[] = 'ssl-mode=REQUIRED';
        }

        $ca = $this->resolveMysqlCaPath();
        if ($ca !== null) {
            $lines[] = 'ssl-ca='.$this->escapeMysqlOptionFileValue($ca);
        }

        $verify = filter_var(
            env('MYSQL_SSL_VERIFY_SERVER_CERT', $ca !== null ? 'true' : 'false'),
            FILTER_VALIDATE_BOOLEAN
        );
        if (! $verify) {
            $lines[] = 'ssl-verify-server-cert=false';
        }

        return $lines;
    }

    private function resolveMysqlCaPath(): ?string
    {
        $caEnv = env('MYSQL_ATTR_SSL_CA');
        if (is_string($caEnv) && $caEnv !== '' && is_readable($caEnv)) {
            return $caEnv;
        }

        $bundled = base_path('docker/digitalocean-ca-certificate.crt');
        if (is_readable($bundled)) {
            return $bundled;
        }

        return null;
    }

    /** @return list<string> */
    private function extraMysqldumpArgsFromConfig(): array
    {
        $raw = config('haztrack.mysqldump_extra_args');
        if (is_array($raw)) {
            return array_values(array_filter(array_map('strval', $raw)));
        }

        return [];
    }

    private function mysqlDumpFailedException(Process $process, string $binary): \RuntimeException
    {
        $err = $process->getErrorOutput();
        $out = $process->getOutput();
        $combined = trim($err."\n".$out);
        $combined = trim(preg_replace("/\n{3,}/", "\n\n", $combined) ?? $combined);
        $code = $process->getExitCode();

        $hint = '';
        $lower = strtolower($combined);
        $binLower = strtolower($binary);

        if ($code === 127 || str_contains($lower, 'not recognized') || str_contains($lower, 'is not recognized')
            || str_contains($lower, 'no such file or directory') || str_contains($lower, 'cannot find')) {
            $hint = ' mysqldump was not found. Install MySQL client tools and set MYSQLDUMP_PATH in .env to the full path'
                .' (Windows example: C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe).';
        } elseif (str_contains($lower, 'ssl') || str_contains($lower, 'tls') || str_contains($lower, 'certificate')) {
            $hint = ' For managed MySQL over TLS, set MYSQL_ATTR_SSL_CA or MYSQL_SSL=true and ensure ssl-mode / ssl-ca match your host.';
        } elseif (str_contains($lower, 'access denied')) {
            $hint = ' Check DB_USERNAME / DB_PASSWORD in .env.';
        } elseif (str_contains($lower, 'column_statistics') || str_contains($lower, 'column statistics')) {
            $hint = ' If this persists, set MYSQLDUMP_EXTRA_ARGS=\'["--column-statistics=0"]\' or use the MySQL 8.0+ client mysqldump.';
        }

        $msg = $combined !== '' ? $combined : 'mysqldump failed with exit code '.($code ?? 'unknown').'.';
        if ($binLower !== 'mysqldump' && ! str_contains($msg, $binary)) {
            $msg .= ' (binary: '.$binary.')';
        }
        if ($combined === '') {
            $msg .= ' No error text was returned (common on Windows). Confirm mysqldump runs in a terminal: mysqldump --version. Set MYSQLDUMP_PATH to mysqldump.exe if needed.';
        }

        return new \RuntimeException($msg.$hint);
    }
}
