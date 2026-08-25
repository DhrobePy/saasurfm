<?php
/**
 * GoogleDriveService — Pure cURL Google Drive API v3 client (OAuth2).
 * No Composer / SDK required. Uses a refresh token for long-lived access.
 * Supports Shared Drives and personal My Drive folders.
 *
 * Requires: PHP openssl + curl extensions (standard on any modern host).
 */
class GoogleDriveService
{
    private const API      = 'https://www.googleapis.com/drive/v3';
    private const UPLOAD   = 'https://www.googleapis.com/upload/drive/v3';
    private const TOKEN_EP = 'https://oauth2.googleapis.com/token';

    // Auth mode: 'oauth2' | 'service_account'
    private string  $authMode     = 'oauth2';
    private string  $clientId     = '';
    private string  $clientSecret = '';
    private string  $refreshToken = '';
    private array   $serviceAccount = [];  // decoded JSON for SA mode
    private string  $tokenCache;
    private ?string $accessToken = null;
    private int     $tokenExpiry = 0;

    // ── Constructors ──────────────────────────────────────────────────────────

    /**
     * OAuth2 refresh-token mode (legacy — token can expire/be revoked).
     * Throws if any credential is empty.
     */
    public function __construct(string $clientId, string $clientSecret, string $refreshToken)
    {
        if (empty($clientId) || empty($clientSecret) || empty($refreshToken)) {
            throw new RuntimeException(
                "Google Drive OAuth2 credentials are incomplete. " .
                "Set GOOGLE_OAUTH_CLIENT_ID, GOOGLE_OAUTH_CLIENT_SECRET, and " .
                "GOOGLE_OAUTH_REFRESH_TOKEN in config.php, or switch to service-account auth " .
                "using GoogleDriveService::fromServiceAccount()."
            );
        }
        $this->authMode     = 'oauth2';
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
        $this->refreshToken = $refreshToken;
        $this->tokenCache   = sys_get_temp_dir() . '/ufm_gdrive_' . md5($clientId) . '.token';
    }

    /**
     * Service-account mode — RECOMMENDED (tokens never expire; no user consent needed).
     *
     * How to set up (one time):
     *   1. console.cloud.google.com → your project → APIs & Services → Credentials
     *   2. Create Credentials → Service Account → name it "ufm-erp-backup" → Done
     *   3. Click the service account → Keys → Add Key → JSON → Download
     *   4. Upload the JSON to /secure/google-service-account.json on the server
     *   5. In Google Drive, share your backup folder with the service account email
     *      (shown in the JSON as "client_email") — give it "Editor" access
     *   6. Call this method: GoogleDriveService::fromServiceAccount(GOOGLE_SERVICE_ACCOUNT_JSON)
     */
    public static function fromServiceAccount(string $jsonPath): self
    {
        if (!file_exists($jsonPath)) {
            throw new RuntimeException("Service account JSON not found: $jsonPath");
        }
        $sa = json_decode(file_get_contents($jsonPath), true);
        if (empty($sa['type']) || $sa['type'] !== 'service_account') {
            throw new RuntimeException("Invalid service account JSON (type must be 'service_account'): $jsonPath");
        }
        if (empty($sa['client_email']) || $sa['client_email'] === 'REPLACE_ME@REPLACE_ME.iam.gserviceaccount.com') {
            throw new RuntimeException(
                "Service account JSON has placeholder values. " .
                "Download a real service account JSON from Google Cloud Console and " .
                "replace /secure/google-service-account.json on the server."
            );
        }
        if (empty($sa['private_key']) || $sa['private_key'] === 'REPLACE_ME') {
            throw new RuntimeException("Service account JSON is missing a valid private_key.");
        }

        $instance = new self('_placeholder_', '_placeholder_', '_placeholder_');
        $instance->authMode      = 'service_account';
        $instance->serviceAccount = $sa;
        $instance->tokenCache    = sys_get_temp_dir() . '/ufm_sa_' . md5($sa['client_email']) . '.token';
        // Clear OAuth2 fields (we used placeholder values to bypass the constructor guard)
        $instance->clientId     = '';
        $instance->clientSecret = '';
        $instance->refreshToken = '';
        return $instance;
    }

    // ── Public File Operations ────────────────────────────────────────────────

    /**
     * Upload a local file to Drive. Returns the Drive file metadata array.
     */
    public function uploadFile(
        string $localPath,
        string $driveName,
        string $mimeType,
        string $folderId
    ): array {
        if (!is_readable($localPath)) {
            throw new RuntimeException("Cannot read local file: $localPath");
        }
        $content = file_get_contents($localPath);
        if ($content === false) {
            throw new RuntimeException("Failed to read: $localPath");
        }
        return $this->uploadContent($content, $driveName, $mimeType, $folderId);
    }

    /**
     * Upload raw string content to Drive. Returns the Drive file metadata array.
     * Uses resumable upload — works for any file size.
     */
    public function uploadContent(
        string $content,
        string $driveName,
        string $mimeType,
        string $folderId
    ): array {
        $token    = $this->getAccessToken();
        $metadata = json_encode(['name' => $driveName, 'parents' => [$folderId]]);
        $size     = strlen($content);

        // ── Step 1: Initiate resumable upload session ─────────────────────
        $resp = $this->curl('POST',
            self::UPLOAD . '/files?uploadType=resumable&supportsAllDrives=true',
            [
                "Authorization: Bearer $token",
                'Content-Type: application/json',
                "Content-Length: " . strlen($metadata),
                "X-Upload-Content-Type: $mimeType",
                "X-Upload-Content-Length: $size",
            ],
            $metadata,
            captureHeaders: true
        );

        if ($resp['status'] !== 200) {
            throw new RuntimeException("Drive upload init failed ({$resp['status']}): {$resp['body']}");
        }

        $uploadUrl = $resp['headers']['location'] ?? $resp['headers']['Location'] ?? '';
        if (!$uploadUrl) {
            throw new RuntimeException('Drive did not return an upload URI.');
        }

        // ── Step 2: PUT the actual bytes ──────────────────────────────────
        $put = $this->curl('PUT', $uploadUrl,
            [
                "Content-Type: $mimeType",
                "Content-Length: $size",
                "Content-Range: bytes 0-" . ($size - 1) . "/$size",
            ],
            $content,
            captureHeaders: false
        );

        if (!in_array($put['status'], [200, 201], true)) {
            throw new RuntimeException("Drive upload PUT failed ({$put['status']}): {$put['body']}");
        }

        return json_decode($put['body'], true) ?? [];
    }

    /**
     * List files in a Drive folder. Returns array of file metadata.
     */
    public function listFiles(string $folderId, int $limit = 100): array
    {
        $token  = $this->getAccessToken();
        $q      = urlencode("'$folderId' in parents and trashed=false");
        $fields = urlencode('files(id,name,size,mimeType,createdTime,webViewLink,webContentLink)');

        $resp = $this->curl('GET',
            self::API . "/files?q=$q&orderBy=createdTime+desc&pageSize=$limit&fields=$fields&supportsAllDrives=true&includeItemsFromAllDrives=true",
            ["Authorization: Bearer $token"]
        );

        if ($resp['status'] !== 200) {
            throw new RuntimeException("Drive list failed ({$resp['status']}): {$resp['body']}");
        }

        $data = json_decode($resp['body'], true);
        return $data['files'] ?? [];
    }

    /**
     * Delete a file from Drive by its file ID.
     */
    public function deleteFile(string $fileId): bool
    {
        $token = $this->getAccessToken();
        $resp  = $this->curl('DELETE',
            self::API . "/files/$fileId?supportsAllDrives=true",
            ["Authorization: Bearer $token"]
        );
        return $resp['status'] === 204;
    }

    /**
     * Get metadata for a single file.
     */
    public function getFileMetadata(string $fileId): array
    {
        $token  = $this->getAccessToken();
        $fields = urlencode('id,name,size,mimeType,createdTime,webViewLink,webContentLink');
        $resp   = $this->curl('GET',
            self::API . "/files/$fileId?fields=$fields&supportsAllDrives=true",
            ["Authorization: Bearer $token"]
        );
        if ($resp['status'] !== 200) {
            throw new RuntimeException("Drive getMetadata failed ({$resp['status']}): {$resp['body']}");
        }
        return json_decode($resp['body'], true) ?? [];
    }

    /**
     * Create a folder in Drive. Returns the new folder's metadata.
     */
    public function createFolder(string $name, string $parentId = ''): array
    {
        $token    = $this->getAccessToken();
        $metadata = ['name' => $name, 'mimeType' => 'application/vnd.google-apps.folder'];
        if ($parentId) {
            $metadata['parents'] = [$parentId];
        }

        $body = json_encode($metadata);
        $resp = $this->curl('POST',
            self::API . '/files?supportsAllDrives=true',
            [
                "Authorization: Bearer $token",
                'Content-Type: application/json',
                "Content-Length: " . strlen($body),
            ],
            $body
        );

        if ($resp['status'] !== 200) {
            throw new RuntimeException("Drive createFolder failed ({$resp['status']}): {$resp['body']}");
        }
        return json_decode($resp['body'], true) ?? [];
    }

    /**
     * Find a folder by name inside a parent, or create it if missing.
     */
    public function getOrCreateFolder(string $name, string $parentId = ''): string
    {
        $token = $this->getAccessToken();
        $q     = "mimeType='application/vnd.google-apps.folder' and name=" .
                 json_encode($name) . " and trashed=false" .
                 ($parentId ? " and '$parentId' in parents" : '');

        $resp = $this->curl('GET',
            self::API . '/files?q=' . urlencode($q) . '&fields=' . urlencode('files(id,name)') . '&supportsAllDrives=true&includeItemsFromAllDrives=true',
            ["Authorization: Bearer $token"]
        );

        $data = json_decode($resp['body'], true);
        if (!empty($data['files'][0]['id'])) {
            return $data['files'][0]['id'];
        }

        $folder = $this->createFolder($name, $parentId);
        return $folder['id'] ?? throw new RuntimeException("Could not create Drive folder: $name");
    }

    /**
     * Returns Drive storage quota info.
     */
    public function getStorageInfo(): array
    {
        $token = $this->getAccessToken();
        $resp  = $this->curl('GET',
            self::API . '/about?fields=' . urlencode('storageQuota'),
            ["Authorization: Bearer $token"]
        );
        $data = json_decode($resp['body'], true);
        return $data['storageQuota'] ?? [];
    }

    /**
     * Make a file publicly readable and return its web view link.
     */
    public function makePublic(string $fileId): string
    {
        $token = $this->getAccessToken();
        $body  = json_encode(['role' => 'reader', 'type' => 'anyone']);
        $this->curl('POST',
            self::API . "/files/$fileId/permissions",
            [
                "Authorization: Bearer $token",
                'Content-Type: application/json',
                "Content-Length: " . strlen($body),
            ],
            $body
        );
        $meta = $this->getFileMetadata($fileId);
        return $meta['webViewLink'] ?? '';
    }

    // ── Auth (OAuth2 Refresh Token) ───────────────────────────────────────────

    private function getAccessToken(): string
    {
        // 1. In-memory cache
        if ($this->accessToken && time() < $this->tokenExpiry - 30) {
            return $this->accessToken;
        }

        // 2. File cache (survives between requests within the same hour)
        if (file_exists($this->tokenCache)) {
            $cached = json_decode(file_get_contents($this->tokenCache), true);
            if (!empty($cached['token']) && time() < ($cached['expiry'] - 30)) {
                $this->accessToken = $cached['token'];
                $this->tokenExpiry = $cached['expiry'];
                return $this->accessToken;
            }
        }

        // 3. Fetch a fresh token via refresh_token grant
        [$token, $expiry] = $this->fetchNewToken();
        $this->accessToken = $token;
        $this->tokenExpiry = $expiry;

        file_put_contents($this->tokenCache,
            json_encode(['token' => $token, 'expiry' => $expiry]),
            LOCK_EX
        );

        return $token;
    }

    private function fetchNewToken(): array
    {
        if ($this->authMode === 'service_account') {
            return $this->fetchServiceAccountToken();
        }
        return $this->fetchOAuth2Token();
    }

    private function fetchOAuth2Token(): array
    {
        $body = http_build_query([
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type'    => 'refresh_token',
        ]);

        $resp = $this->curl('POST', self::TOKEN_EP,
            ['Content-Type: application/x-www-form-urlencoded'],
            $body
        );

        $data = json_decode($resp['body'], true);
        if (empty($data['access_token'])) {
            throw new RuntimeException(
                "Failed to refresh Drive token: " . ($data['error_description'] ?? $resp['body'])
            );
        }
        return [$data['access_token'], time() + (int)($data['expires_in'] ?? 3600)];
    }

    /**
     * Service-account JWT bearer grant (RFC 7523).
     * Signs a JWT with the SA private key and exchanges it for a short-lived access token.
     * Tokens last 1 hour; the in-memory + file cache handles re-use.
     */
    private function fetchServiceAccountToken(): array
    {
        $sa  = $this->serviceAccount;
        $now = time();

        $header = $this->base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $this->base64url(json_encode([
            'iss'   => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive',
            'aud'   => self::TOKEN_EP,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $sig = '';
        $key = openssl_pkey_get_private($sa['private_key']);
        if (!$key) {
            throw new RuntimeException("Cannot load service account private key from JSON.");
        }
        if (!openssl_sign("$header.$claims", $sig, $key, 'SHA256')) {
            throw new RuntimeException("Failed to sign JWT for service account.");
        }

        $jwt  = "$header.$claims." . $this->base64url($sig);
        $body = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        $resp = $this->curl('POST', self::TOKEN_EP,
            ['Content-Type: application/x-www-form-urlencoded'],
            $body
        );

        $data = json_decode($resp['body'], true);
        if (empty($data['access_token'])) {
            throw new RuntimeException(
                "Service account token fetch failed: " . ($data['error_description'] ?? $resp['body'])
            );
        }
        return [$data['access_token'], $now + (int)($data['expires_in'] ?? 3600)];
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // ── cURL Helper ───────────────────────────────────────────────────────────

    private function curl(
        string $method,
        string $url,
        array  $headers = [],
        mixed  $body    = null,
        bool   $captureHeaders = false
    ): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => !$captureHeaders,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER         => $captureHeaders,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("cURL error: $err");
        }

        $parsedHeaders = [];
        $responseBody  = $raw;

        if ($captureHeaders) {
            $parts       = explode("\r\n\r\n", $raw);
            $headerBlock = '';
            foreach ($parts as $i => $part) {
                if (stripos($part, 'HTTP/') === 0) {
                    $headerBlock  = $part;
                    $responseBody = implode("\r\n\r\n", array_slice($parts, $i + 1));
                }
            }
            foreach (explode("\r\n", $headerBlock) as $line) {
                if (strpos($line, ': ') !== false) {
                    [$k, $v] = explode(': ', $line, 2);
                    $parsedHeaders[strtolower(trim($k))] = trim($v);
                }
            }
        }

        return ['status' => $status, 'headers' => $parsedHeaders, 'body' => $responseBody];
    }
}