<?php

require_once dirname(__FILE__) . '/AiBridgeEmailTemplate.php';

class AiBridgeEmailSendPreview
{
    private const ALLOWED_FIELDS = array('template', 'to', 'to_name', 'variables');
    private const MAX_VARIABLES = 30;

    public function build(array $payload, $shopId, $languageId)
    {
        $errors = array();

        $unknownFields = array_diff(array_keys($payload), self::ALLOWED_FIELDS);
        if (!empty($unknownFields)) {
            $errors[] = 'Unsupported field.';
        }

        $canonical = array();
        $template = null;

        $templateName = $payload['template'] ?? null;
        if (!is_string($templateName) || $templateName === '') {
            $errors[] = 'Invalid template.';
        } else {
            $template = AiBridgeEmailTemplate::findByName($templateName);
            if ($template === null) {
                $errors[] = 'Template not found.';
            } else {
                $canonical['template'] = $templateName;
            }
        }

        $to = $payload['to'] ?? null;
        if (!is_string($to) || !Validate::isEmail($to)) {
            $errors[] = 'Invalid to.';
        } else {
            $canonical['to'] = $to;
        }

        $toName = $payload['to_name'] ?? '';
        if (!is_string($toName) || Tools::strlen($toName) > 128) {
            $errors[] = 'Invalid to_name.';
        } else {
            $canonical['to_name'] = $toName;
        }

        $variables = array();
        if (array_key_exists('variables', $payload)) {
            if (!is_array($payload['variables']) || count($payload['variables']) > self::MAX_VARIABLES) {
                $errors[] = 'Invalid variables.';
            } else {
                foreach ($payload['variables'] as $key => $value) {
                    if (!is_string($key) || !preg_match('/\A[a-zA-Z0-9_]{1,64}\z/', $key)
                        || !(is_scalar($value) || $value === null)) {
                        $errors[] = 'Invalid variables.';
                        break;
                    }
                    $variables[$key] = (string) $value;
                }
            }
        }
        ksort($variables, SORT_STRING);
        $canonical['variables'] = $variables;

        if (!empty($errors)) {
            return array(
                'valid' => false,
                'errors' => array_values(array_unique($errors)),
            );
        }

        $canonical['shop_id'] = (int) $shopId;
        $canonical['language_id'] = (int) $languageId;
        $canonical = $this->canonicalize($canonical);

        $renderedSubject = AiBridgeEmailTemplate::render($template->subject, $variables);
        $renderedHtml = AiBridgeEmailTemplate::render($template->html_body, $variables);

        $change = array(
            'field' => 'email_send',
            'current' => null,
            'proposed' => array(
                'template' => $templateName,
                'to' => $to,
                'to_name' => $toName,
                'variables' => $variables,
                'preview_subject' => $renderedSubject,
                'preview_html' => $renderedHtml,
            ),
            'changed' => true,
            'validation' => array('ok' => true, 'errors' => array()),
        );

        return array(
            'valid' => true,
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
