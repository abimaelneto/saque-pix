<?php

declare(strict_types=1);

$host = $argv[1] ?? 'mysql';
$port = (int) ($argv[2] ?? 3306);
$user = $argv[3] ?? 'root';
$password = $argv[4] ?? 'root';
$timeout = (int) ($argv[5] ?? 60);

$startTime = time();
$maxTime = $startTime + $timeout;

echo "Aguardando MySQL em {$host}:{$port}...\n";

while (time() < $maxTime) {
    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port}",
            $user,
            $password,
            [
                PDO::ATTR_TIMEOUT => 2,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]
        );
        
        // Testa se consegue executar uma query
        $pdo->query('SELECT 1');
        
        echo "✅ MySQL está pronto!\n";
        exit(0);
    } catch (PDOException $e) {
        $elapsed = time() - $startTime;
        if ($elapsed % 4 === 0) {
            echo "   Aguardando MySQL... ({$elapsed}/{$timeout} segundos)\n";
        }
        sleep(1);
    }
}

echo "❌ Timeout: MySQL não ficou pronto em {$timeout} segundos\n";
exit(1);


