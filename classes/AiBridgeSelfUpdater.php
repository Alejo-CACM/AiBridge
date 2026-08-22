<?php

class AiBridgeSelfUpdater
{
    private const MAX_BACKUPS = 3;

    public function fetchManifest($url)
    {
        if (!is_string($url) || strpos($url, 'https://') !== 0) {
            return null;
        }

        $body = $this->httpGet($url, 10);
        if ($body === null) {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data)
            || !isset($data['version'], $data['download_url'], $data['sha256'])
            || !is_string($data['version'])
            || !is_string($data['download_url'])
            || !is_string($data['sha256'])
            || strpos($data['download_url'], 'https://') !== 0
            || !preg_match('/\A[0-9a-f]{64}\z/i', $data['sha256'])) {
            return null;
        }

        return $data;
    }

    public function hasUpdate($currentVersion, array $manifest)
    {
        return version_compare((string) $manifest['version'], (string) $currentVersion, '>');
    }

    /**
     * Downloads, verifies, backs up, and installs the update described by
     * $manifest. Restores the previous backup automatically on any failure.
     * Appends progress/error messages to $log and returns success.
     */
    public function performUpdate(array $manifest, array &$log)
    {
        $workDir = rtrim(_PS_CACHE_DIR_, '/\\') . DIRECTORY_SEPARATOR . 'aibridge_updates' . DIRECTORY_SEPARATOR;
        if (!is_dir($workDir) && !@mkdir($workDir, 0700, true)) {
            $log[] = 'No se pudo crear el directorio temporal de actualización.';

            return false;
        }
        if (!is_file($workDir . 'index.php')) {
            @file_put_contents($workDir . 'index.php', "<?php exit;\n");
        }

        $zipPath = $workDir . 'update_' . bin2hex(random_bytes(8)) . '.zip';
        $body = $this->httpGet($manifest['download_url'], 90);
        if ($body === null) {
            $log[] = 'No se pudo descargar el paquete de actualización.';

            return false;
        }
        if (@file_put_contents($zipPath, $body) === false) {
            $log[] = 'No se pudo guardar el paquete descargado.';

            return false;
        }
        unset($body);

        $actualHash = hash_file('sha256', $zipPath);
        if ($actualHash === false || !hash_equals(strtolower((string) $manifest['sha256']), strtolower((string) $actualHash))) {
            @unlink($zipPath);
            $log[] = 'El checksum del paquete no coincide con el manifiesto — descarga corrupta o fuente no confiable. Actualización cancelada.';

            return false;
        }
        $log[] = 'Paquete descargado y verificado (sha256 correcto).';

        $extractDir = $workDir . 'extract_' . bin2hex(random_bytes(8)) . DIRECTORY_SEPARATOR;
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            @unlink($zipPath);
            $log[] = 'El paquete descargado no es un archivo zip válido.';

            return false;
        }
        $extracted = $zip->extractTo($extractDir);
        $zip->close();
        @unlink($zipPath);

        if (!$extracted) {
            Tools::deleteDirectory($extractDir);
            $log[] = 'No se pudo extraer el paquete de actualización.';

            return false;
        }

        $stagedModuleDir = $extractDir . 'aibridge' . DIRECTORY_SEPARATOR;
        if (!is_file($stagedModuleDir . 'aibridge.php')) {
            Tools::deleteDirectory($extractDir);
            $log[] = 'El paquete no contiene un módulo aibridge válido (falta aibridge.php).';

            return false;
        }

        $stagedVersion = $this->readDeclaredVersion($stagedModuleDir . 'aibridge.php');
        if ($stagedVersion === null || version_compare($stagedVersion, (string) $manifest['version'], '!=')) {
            Tools::deleteDirectory($extractDir);
            $log[] = 'La versión declarada dentro del paquete no coincide con la del manifiesto. Actualización cancelada.';

            return false;
        }
        $log[] = 'Versión verificada dentro del paquete: ' . $stagedVersion . '.';

        $liveModuleDir = rtrim(_PS_MODULE_DIR_, '/\\') . DIRECTORY_SEPARATOR . 'aibridge';
        $backupDir = rtrim(_PS_MODULE_DIR_, '/\\') . DIRECTORY_SEPARATOR . 'aibridge_backup_' . date('YmdHis');

        if (!$this->copyDirectory($liveModuleDir, $backupDir, $log)) {
            Tools::deleteDirectory($extractDir);
            Tools::deleteDirectory($backupDir);
            $log[] = 'No se pudo crear el respaldo antes de actualizar. Actualización cancelada por seguridad — no se tocó ningún archivo en vivo.';

            return false;
        }
        $log[] = 'Respaldo creado: ' . basename($backupDir) . '.';

        if (!$this->copyDirectory($stagedModuleDir, $liveModuleDir, $log)) {
            $log[] = 'Falló la copia de los archivos nuevos — restaurando respaldo.';
            $this->restoreBackup($liveModuleDir, $backupDir, $log);
            Tools::deleteDirectory($extractDir);

            return false;
        }
        Tools::deleteDirectory($extractDir);
        $log[] = 'Archivos del módulo reemplazados.';

        try {
            $module = Module::getInstanceByName('aibridge');
            if (!($module instanceof Module)) {
                throw new \RuntimeException('Module::getInstanceByName returned no instance after file replacement.');
            }

            if (Module::needUpgrade($module)) {
                $result = $module->runUpgradeModule();
                if (empty($result['success']) && !empty($result['upgrade_file_left'])) {
                    $log[] = 'Falló un script de actualización de base de datos (versión '
                        . (isset($result['version_fail']) ? $result['version_fail'] : '?')
                        . ') — restaurando respaldo.';
                    $this->restoreBackup($liveModuleDir, $backupDir, $log);

                    return false;
                }
                $log[] = 'Scripts de actualización de base de datos ejecutados correctamente.';
            } else {
                Module::upgradeModuleVersion('aibridge', (string) $manifest['version']);
                $log[] = 'Versión registrada en Back Office actualizada (sin cambios de base de datos).';
            }
        } catch (\Throwable $exception) {
            $log[] = 'Error ejecutando la actualización: ' . $exception->getMessage() . ' — restaurando respaldo.';
            $this->restoreBackup($liveModuleDir, $backupDir, $log);

            return false;
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        $this->pruneOldBackups();
        $log[] = 'Actualización completada a la versión ' . $manifest['version'] . '.';

        return true;
    }

    private function restoreBackup($liveModuleDir, $backupDir, array &$log)
    {
        Tools::deleteDirectory($liveModuleDir);
        if ($this->copyDirectory($backupDir, $liveModuleDir, $log)) {
            $log[] = 'Respaldo restaurado correctamente. El módulo sigue en la versión anterior.';
        } else {
            $log[] = 'ATENCIÓN: el respaldo no pudo restaurarse automáticamente. Revisa manualmente la carpeta '
                . basename($backupDir) . ' en el directorio de módulos.';
        }
    }

    /**
     * Tools::recurseCopy() has no return statement on its success path (it
     * implicitly returns null, which is falsy) — its return value can never
     * be trusted to mean success. Verify by checking the destination
     * actually contains aibridge.php afterwards instead. Captures the real
     * PHP warnings (mkdir/copy failures) that recurseCopy() emits but never
     * surfaces, since those are the only clue to *why* a copy failed.
     */
    private function copyDirectory($src, $dst, array &$log = null)
    {
        if (!is_dir($src)) {
            if ($log !== null) $log[] = 'Copia cancelada: el origen "' . $src . '" no existe.';

            return false;
        }

        $parent = dirname(rtrim($dst, '/\\'));
        if ($log !== null) {
            $log[] = 'Diagnóstico: origen=' . $src . ' | destino=' . $dst
                . ' | carpeta padre del destino escribible=' . (is_writable($parent) ? 'sí' : 'no')
                . ' | origen escribible=' . (is_writable($src) ? 'sí' : 'no');
        }

        $warnings = array();
        set_error_handler(function ($errno, $errstr) use (&$warnings) {
            $warnings[] = $errstr;

            return true;
        });
        Tools::recurseCopy($src, $dst);
        restore_error_handler();

        if ($warnings && $log !== null) {
            $log[] = 'Avisos de PHP durante la copia: ' . implode(' | ', array_slice($warnings, 0, 5));
        }

        return is_file(rtrim($dst, '/\\') . DIRECTORY_SEPARATOR . 'aibridge.php');
    }

    private function readDeclaredVersion($file)
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }
        if (preg_match('/\$this->version\s*=\s*[\'"]([0-9]+\.[0-9]+\.[0-9]+)[\'"]/', $content, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function pruneOldBackups()
    {
        $moduleDir = rtrim(_PS_MODULE_DIR_, '/\\') . DIRECTORY_SEPARATOR;
        $entries = glob($moduleDir . 'aibridge_backup_*', GLOB_ONLYDIR);
        if (!is_array($entries)) {
            return;
        }
        sort($entries);
        $excess = count($entries) - self::MAX_BACKUPS;
        for ($i = 0; $i < $excess; ++$i) {
            Tools::deleteDirectory($entries[$i]);
        }
    }

    private function httpGet($url, $timeout)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_USERAGENT => 'AiBridgeSelfUpdater',
            ));
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return ($body !== false && $status >= 200 && $status < 300) ? $body : null;
        }

        $context = stream_context_create(array(
            'http' => array('timeout' => $timeout, 'follow_location' => 1),
            'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
        ));
        $body = @file_get_contents($url, false, $context);

        return $body === false ? null : $body;
    }
}
