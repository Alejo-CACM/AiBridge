<?php

require_once dirname(__FILE__) . '/AiBridgeEmailTemplate.php';

class AiBridgeEmailTemplateCreatePreview
{
    private const ALLOWED_FIELDS = array('name', 'subject', 'html_body');
    private const MAX_HTML_BODY_LENGTH = 200000;

    public function build(array $payload, $shopId, $languageId)
    {
        $errors = array();
        $conflict = false;

        $unknownFields = array_diff(array_keys($payload), self::ALLOWED_FIELDS);
        if (!empty($unknownFields)) {
            $errors[] = 'Unsupported field.';
        }

        $canonical = array();

        $name = $payload['name'] ?? null;
        if (!is_string($name) || !preg_match('/\A[a-z0-9][a-z0-9_-]{1,62}[a-z0-9]\z/', $name)) {
            $errors[] = 'Invalid name (use lowercase letters, digits, "-" or "_", 3-64 chars).';
        } else {
            $canonical['name'] = $name;
        }

        if (isset($canonical['name']) && AiBridgeEmailTemplate::findByName($canonical['name']) !== null) {
            $errors[] = 'A template with this name already exists.';
            $conflict = true;
        }

        $subject = $payload['subject'] ?? null;
        if (!is_string($subject) || trim($subject) === '' || Tools::strlen($subject) > 255) {
            $errors[] = 'Invalid subject.';
        } else {
            $canonical['subject'] = $subject;
        }

        $htmlBody = $payload['html_body'] ?? null;
        if (!is_string($htmlBody) || trim($htmlBody) === '' || Tools::strlen($htmlBody) > self::MAX_HTML_BODY_LENGTH
            || stripos($htmlBody, '<script') !== false) {
            $errors[] = 'Invalid html_body.';
        } else {
            $canonical['html_body'] = $htmlBody;
        }

        if (!empty($errors)) {
            return array(
                'valid' => false,
                'conflict' => $conflict,
                'errors' => array_values(array_unique($errors)),
            );
        }

        $canonical['shop_id'] = (int) $shopId;
        $canonical['language_id'] = (int) $languageId;
        $canonical = $this->canonicalize($canonical);

        $change = array(
            'field' => 'email_template_create',
            'current' => null,
            'proposed' => $canonical,
            'changed' => true,
            'validation' => array('ok' => true, 'errors' => array()),
        );

        return array(
            'valid' => true,
            'conflict' => false,
            'shop_id' => (int) $shopId,
            'language_id' => (int) $languageId,
            'canonical_payload' => $canonical,
            'payload_hash' => hash('sha256', json_encode($canonical)),
            'changes' => array($change),
        );
    }

    private function canonicalize(array $payload)
    {
        ksort($payload, SORT_STRING);

        return $payload;
    }
}
