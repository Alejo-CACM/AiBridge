<?php

require_once dirname(__FILE__) . '/../../classes/AiBridgeUpload.php';

class AibridgeImagestageModuleFrontController extends ModuleFrontController
{
    private const MAX_FILE_SIZE = 8388608;
    private const MAX_DIMENSION = 6000;

    public function initContent()
    {
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                $this->sendJson(400, $this->error('invalid_request', 'Invalid upload request.'));
            }
            if (!is_object($this->module) || !$this->module->isValidApiToken()) {
                $this->sendJson(401, $this->error('unauthorized', 'Invalid or missing API token.'));
            }
            if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
                $this->sendJson(400, $this->error('invalid_file', 'A valid image file is required.'));
            }

            $file = $_FILES['file'];
            if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                $this->sendJson(400, $this->error('invalid_file', 'A valid image file is required.'));
            }
            if ((int) $file['size'] > self::MAX_FILE_SIZE) {
                $this->sendJson(413, $this->error('file_too_large', 'Image file is too large.'));
            }

            $imageInfo = @getimagesize($file['tmp_name']);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $types = array(IMAGETYPE_JPEG => array('image/jpeg', 'jpg'), IMAGETYPE_PNG => array('image/png', 'png'));
            if (!is_array($imageInfo) || !isset($types[$imageInfo[2]])
                || $mime !== $types[$imageInfo[2]][0]
                || (int) $imageInfo[0] > self::MAX_DIMENSION
                || (int) $imageInfo[1] > self::MAX_DIMENSION) {
                $this->sendJson(400, $this->error('invalid_file', 'A valid JPEG or PNG image is required.'));
            }

            $directory = $this->getUploadDirectory();
            AiBridgeUpload::expireStagedUploads($directory);
            $uploadToken = bin2hex(random_bytes(32));
            $fileName = bin2hex(random_bytes(24)) . '.' . $types[$imageInfo[2]][1];
            $path = $directory . $fileName;
            if (!move_uploaded_file($file['tmp_name'], $path)) {
                $this->sendJson(500, $this->error('upload_failed', 'Image staging failed internally.'));
            }

            $now = date('Y-m-d H:i:s');
            $expiresAt = date('Y-m-d H:i:s', time() + 86400);
            $upload = new AiBridgeUpload();
            $upload->token_hash = hash('sha256', $uploadToken);
            $upload->file_path = $path;
            $upload->mime = $mime;
            $upload->extension = $types[$imageInfo[2]][1];
            $upload->file_size = (int) $file['size'];
            $upload->width = (int) $imageInfo[0];
            $upload->height = (int) $imageInfo[1];
            $upload->status = 'staged';
            $upload->created_at = $now;
            $upload->expires_at = $expiresAt;
            if (!$upload->add()) {
                @unlink($path);
                $this->sendJson(500, $this->error('upload_failed', 'Image staging failed internally.'));
            }

            $this->sendJson(200, array('success' => true, 'data' => array(
                'upload_token' => $uploadToken,
                'mime' => $mime,
                'size' => (int) $file['size'],
                'width' => (int) $imageInfo[0],
                'height' => (int) $imageInfo[1],
                'expires_at' => $expiresAt,
            )));
        } catch (\Throwable $exception) {
            $this->sendJson(500, $this->error('upload_internal_error', 'Image staging failed internally.'));
        }
    }

    private function getUploadDirectory()
    {
        $directory = _PS_CACHE_DIR_ . 'aibridge_uploads' . DIRECTORY_SEPARATOR;
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new Exception('Upload directory creation failed.');
        }
        if (!is_file($directory . 'index.php')) {
            file_put_contents($directory . 'index.php', "<?php exit;\n");
        }
        if (!is_file($directory . '.htaccess')) {
            file_put_contents($directory . '.htaccess', "Require all denied\nDeny from all\n");
        }
        return $directory;
    }

    private function error($code, $message)
    {
        return array('success' => false, 'error' => array('code' => $code, 'message' => $message));
    }

    private function sendJson($statusCode, array $payload)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
}