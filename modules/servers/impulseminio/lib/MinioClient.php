<?php
/**
 * Impulse Hosting - MinIO Client Library v1.1
 * Wraps MinIO mc CLI for the WHMCS provisioning module.
 * Supports multi-bucket policies and service account (access key) management.
 * @package ImpulseMinio
 */
namespace WHMCS\Module\Server\ImpulseMinio;

class MinioClient
{
    private $endpoint;
    private $accessKey;
    private $secretKey;
    private $mcAlias;
    private $mcPath;
    private $useSSL;
    private $logModule = 'impulseminio';

    public function __construct(string $endpoint, string $accessKey, string $secretKey, string $mcPath = '/usr/local/bin/mc', bool $useSSL = true)
    {
        $this->endpoint  = rtrim($endpoint, '/');
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->mcPath    = $mcPath;
        $this->useSSL    = $useSSL;
        $this->mcAlias   = 'impulse';
    }

    private function mc(string $command, array $args = [], bool $jsonOutput = false): array
    {
        $cmd = escapeshellcmd($this->mcPath);
        if ($jsonOutput) $cmd .= ' --json';
        $cmd .= ' ' . $command;
        foreach ($args as $arg) $cmd .= ' ' . escapeshellarg($arg);
        $env = 'MC_CONFIG_DIR=/tmp/.mc-impulse HOME=/tmp ';
        $fullCmd = $env . $cmd . ' 2>&1';
        $output = [];
        $exitCode = 0;
        exec($fullCmd, $output, $exitCode);
        $outputStr = implode("\n", $output);
        logModuleCall($this->logModule, 'mc: ' . $command, ['args' => $args], $outputStr, null, [$this->accessKey, $this->secretKey]);
        return ['success' => ($exitCode === 0), 'output' => $outputStr, 'exitCode' => $exitCode];
    }

    public function ensureAlias(): bool
    {
        return $this->mc('alias set', [$this->mcAlias, $this->endpoint, $this->accessKey, $this->secretKey])['success'];
    }

    // === USER MANAGEMENT ===
    public function createUser(string $username, string $password): array
    {
        $this->ensureAlias();
        $result = $this->mc('admin user add', [$this->mcAlias, $username, $password]);
        if (!$result['success']) {
            if (strpos($result['output'], 'already exists') !== false) return ['success' => true, 'error' => null];
            return ['success' => false, 'error' => 'Failed to create user: ' . $result['output']];
        }
        return ['success' => true, 'error' => null];
    }

    public function deleteUser(string $username): array
    {
        $this->ensureAlias();
        $r = $this->mc('admin user remove', [$this->mcAlias, $username]);
        return ['success' => $r['success'], 'error' => $r['success'] ? null : $r['output']];
    }

    public function disableUser(string $username): array
    {
        $this->ensureAlias();
        $r = $this->mc('admin user disable', [$this->mcAlias, $username]);
        return ['success' => $r['success'], 'error' => $r['success'] ? null : $r['output']];
    }

    public function enableUser(string $username): array
    {
        $this->ensureAlias();
        $r = $this->mc('admin user enable', [$this->mcAlias, $username]);
        return ['success' => $r['success'], 'error' => $r['success'] ? null : $r['output']];
    }

    public function getUserInfo(string $username): ?array
    {
        $this->ensureAlias();
        $r = $this->mc('admin user info', [$this->mcAlias, $username], true);
        if (!$r['success']) return null;
        return json_decode($r['output'], true) ?: null;
    }

    // === POLICY MANAGEMENT (MULTI-BUCKET) ===
    public function createUserPolicy(string $username, $bucketNames): array
    {
        if (is_string($bucketNames)) $bucketNames = [$bucketNames];
        $policyName = 'user-' . $username;
        $br = []; $or = [];
        foreach ($bucketNames as $b) { $br[] = 'arn:aws:s3:::' . $b; $or[] = 'arn:aws:s3:::' . $b . '/*'; }

        $policy = json_encode(['Version' => '2012-10-17', 'Statement' => [
            ['Effect' => 'Allow', 'Action' => ['s3:GetBucketLocation','s3:ListBucket','s3:ListBucketMultipartUploads'], 'Resource' => $br],
            ['Effect' => 'Allow', 'Action' => ['s3:GetObject','s3:PutObject','s3:DeleteObject','s3:ListMultipartUploadParts','s3:AbortMultipartUpload'], 'Resource' => $or],
            ['Effect' => 'Allow', 'Action' => ['s3:ListAllMyBuckets'], 'Resource' => ['arn:aws:s3:::*']],
        ]], JSON_PRETTY_PRINT);

        $tmp = tempnam('/tmp', 'minio_policy_');
        file_put_contents($tmp, $policy);
        $this->ensureAlias();
        $r = $this->mc('admin policy create', [$this->mcAlias, $policyName, $tmp]);
        unlink($tmp);
        if (!$r['success']) return ['success' => false, 'error' => 'Failed to create policy: ' . $r['output']];
        $a = $this->mc('admin policy attach', [$this->mcAlias, $policyName, '--user', $username]);
        if (!$a['success']) return ['success' => false, 'error' => 'Policy created but attach failed: ' . $a['output']];
        return ['success' => true, 'error' => null, 'policyName' => $policyName];
    }

    public function applySuspendedPolicy(string $username, $bucketNames): array
    {
        if (is_string($bucketNames)) $bucketNames = [$bucketNames];
        $policyName = 'suspended-' . $username;
        $or = [];
        foreach ($bucketNames as $b) $or[] = 'arn:aws:s3:::' . $b . '/*';
        $policy = json_encode(['Version' => '2012-10-17', 'Statement' => [
            ['Effect' => 'Deny', 'Action' => ['s3:PutObject','s3:DeleteObject','s3:AbortMultipartUpload'], 'Resource' => $or],
        ]], JSON_PRETTY_PRINT);
        $tmp = tempnam('/tmp', 'minio_policy_');
        file_put_contents($tmp, $policy);
        $this->ensureAlias();
        $this->mc('admin policy create', [$this->mcAlias, $policyName, $tmp]);
        unlink($tmp);
        $r = $this->mc('admin policy attach', [$this->mcAlias, $policyName, '--user', $username]);
        return ['success' => $r['success'], 'error' => $r['success'] ? null : $r['output']];
    }

    public function removeSuspendedPolicy(string $username): array
    {
        $pn = 'suspended-' . $username;
        $this->ensureAlias();
        $r = $this->mc('admin policy detach', [$this->mcAlias, $pn, '--user', $username]);
        $this->mc('admin policy remove', [$this->mcAlias, $pn]);
        return ['success' => $r['success'], 'error' => $r['success'] ? null : $r['output']];
    }

    public function deleteUserPolicy(string $username): array
    {
        $pn = 'user-' . $username;
        $this->ensureAlias();
        $this->mc('admin policy detach', [$this->mcAlias, $pn, '--user', $username]);
        $r = $this->mc('admin policy remove', [$this->mcAlias, $pn]);
        return ['success' => $r['success'], 'error' => $r['success'] ? null : $r['output']];
    }

    // === BUCKET MANAGEMENT ===
    public function createBucket(string $bucketName): array
    {
        $this->ensureAlias();
        $r = $this->mc('mb', [$this->mcAlias . '/' . $bucketName]);
        if (!$r['success']) {
            if (strpos($r['output'], 'already own it') !== false || strpos($r['output'], 'already exists') !== false)
                return ['success' => true, 'error' => null];
            return ['success' => false, 'error' => 'Failed to create bucket: ' . $r['output']];
        }
        return ['success' => true, 'error' => null];
    }

    public function deleteBucket(string $bucketName, bool $force = true): array
    {
        $this->ensureAlias();
        if ($force) $this->mc('rm', ['--recursive', '--force', $this->mcAlias . '/' . $bucketName]);
        $r = $this->mc('rb', [$this->mcAlias . '/' . $bucketName]);
        return ['success' => $r['success'], 'error' => $r['success'] ? null : $r['output']];
    }

    // === QUOTA ===
    public function setBucketQuota(string $bucketName, string $quota): array
    {
        $this->ensureAlias();
        $r = $this->mc('quota set', [$this->mcAlias . '/' . $bucketName, '--size', $quota]);
        return ['success' => $r['success'], 'error' => $r['success'] ? null : $r['output']];
    }

    public function clearBucketQuota(string $bucketName): array
    {
        $this->ensureAlias();
        $r = $this->mc('quota clear', [$this->mcAlias . '/' . $bucketName]);
        return ['success' => $r['success'], 'error' => $r['success'] ? null : $r['output']];
    }

    // === ACCESS KEY (SERVICE ACCOUNT) MANAGEMENT ===
    public function createAccessKey(string $parentUser, ?string $name = null, ?array $bucketScope = null): array
    {
        $this->ensureAlias();
        $args = [$this->mcAlias, $parentUser];
        $tmpFile = null;

        if ($bucketScope && count($bucketScope) > 0) {
            $br = []; $or = [];
            foreach ($bucketScope as $b) { $br[] = 'arn:aws:s3:::' . $b; $or[] = 'arn:aws:s3:::' . $b . '/*'; }
            $policy = json_encode(['Version' => '2012-10-17', 'Statement' => [
                ['Effect' => 'Allow', 'Action' => ['s3:GetBucketLocation','s3:ListBucket','s3:ListBucketMultipartUploads'], 'Resource' => $br],
                ['Effect' => 'Allow', 'Action' => ['s3:GetObject','s3:PutObject','s3:DeleteObject','s3:ListMultipartUploadParts','s3:AbortMultipartUpload'], 'Resource' => $or],
                ['Effect' => 'Allow', 'Action' => ['s3:ListAllMyBuckets'], 'Resource' => ['arn:aws:s3:::*']],
            ]], JSON_PRETTY_PRINT);
            $tmpFile = tempnam('/tmp', 'minio_svcacct_');
            file_put_contents($tmpFile, $policy);
            $args[] = '--policy'; $args[] = $tmpFile;
        }
        if ($name) { $args[] = '--name'; $args[] = $name; }

        $r = $this->mc('admin accesskey create', $args, true);
        if ($tmpFile) unlink($tmpFile);

        if (!$r['success']) return ['success' => false, 'accessKey' => '', 'secretKey' => '', 'error' => 'Failed: ' . $r['output']];

        $data = null;
        foreach (array_filter(explode("\n", trim($r['output']))) as $line) {
            $p = json_decode($line, true);
            if ($p && isset($p['accessKey'])) { $data = $p; break; }
        }
        if (!$data) return ['success' => false, 'accessKey' => '', 'secretKey' => '', 'error' => 'Could not parse response: ' . $r['output']];
        return ['success' => true, 'accessKey' => $data['accessKey'], 'secretKey' => $data['secretKey'] ?? '', 'error' => null];
    }

    public function deleteAccessKey(string $accessKeyId): array
    {
        $this->ensureAlias();
        $r = $this->mc('admin accesskey remove', [$this->mcAlias, $accessKeyId]);
        return ['success' => $r['success'], 'error' => $r['success'] ? null : $r['output']];
    }

    public function listAccessKeys(string $parentUser): array
    {
        $this->ensureAlias();
        $r = $this->mc('admin accesskey list', [$this->mcAlias, $parentUser], true);
        if (!$r['success']) return [];
        $keys = [];
        foreach (explode("\n", trim($r['output'])) as $line) {
            $d = json_decode($line, true);
            if ($d && isset($d['accessKey'])) $keys[] = $d;
        }
        return $keys;
    }

    // === USAGE ===
    public function getBucketUsage(string $bucketName): array
    {
        $this->ensureAlias();
        $r = $this->mc('du', [$this->mcAlias . '/' . $bucketName], true);
        if (!$r['success']) return ['success' => false, 'sizeBytes' => 0, 'objectCount' => 0, 'error' => $r['output']];
        $lines = array_filter(explode("\n", trim($r['output'])));
        $data = json_decode(end($lines), true);
        return ['success' => true, 'sizeBytes' => (int)($data['size'] ?? 0), 'objectCount' => (int)($data['objectCount'] ?? ($data['objects'] ?? 0)), 'error' => null];
    }

    public function getBucketBandwidth(string $bucketName): array
    {
        $url = $this->endpoint . '/minio/v2/metrics/bucket';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->generateBearerToken()], CURLOPT_SSL_VERIFYPEER => $this->useSSL]);
        $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $rx = 0; $tx = 0;
        if ($code === 200 && $resp) {
            foreach (explode("\n", $resp) as $line) {
                if (strpos($line, 'minio_bucket_traffic_received_bytes') !== false && strpos($line, 'bucket="'.$bucketName.'"') !== false) { $p = preg_split('/\s+/', trim($line)); $rx = (int)end($p); }
                if (strpos($line, 'minio_bucket_traffic_sent_bytes') !== false && strpos($line, 'bucket="'.$bucketName.'"') !== false) { $p = preg_split('/\s+/', trim($line)); $tx = (int)end($p); }
            }
        }
        return ['bytesReceived' => $rx, 'bytesSent' => $tx];
    }

    // === HELPERS ===
    public static function bucketName(string $input): string
    {
        $n = strtolower(preg_replace('/[^a-zA-Z0-9-]/', '-', $input));
        $n = trim($n, '-'); $n = substr($n, 0, 63);
        return strlen($n) < 3 ? $n . '-bucket' : $n;
    }

    public static function generateUsername(int $serviceId, string $email): string
    {
        $e = strtolower(explode('@', $email)[0]);
        $e = substr(preg_replace('/[^a-z0-9]/', '', $e), 0, 16);
        return 'impulse-' . $serviceId . '-' . $e;
    }

    public static function generatePassword(int $length = 24): string
    {
        $c = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $p = '';
        for ($i = 0; $i < $length; $i++) $p .= $c[random_int(0, strlen($c) - 1)];
        return $p;
    }

    private function generateBearerToken(): string { return base64_encode($this->accessKey . ':' . $this->secretKey); }

    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $u = ['B','KB','MB','GB','TB']; $bytes = max($bytes, 0);
        $p = floor(($bytes ? log($bytes) : 0) / log(1024)); $p = min($p, 4);
        return round($bytes / pow(1024, $p), $precision) . ' ' . $u[$p];
    }

    public function testConnection(): array
    {
        $this->ensureAlias();
        $r = $this->mc('admin info', [$this->mcAlias]);
        return $r['success'] ? ['success' => true, 'message' => 'OK'] : ['success' => false, 'message' => 'Failed: ' . $r['output']];
    }
}
