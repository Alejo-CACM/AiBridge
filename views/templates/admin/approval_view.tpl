<div class="panel">
    <h3>AI Bridge Approval Request</h3>
    <dl>
        <dt>UUID</dt><dd>{$approval_request->uuid|escape:'html':'UTF-8'}</dd>
        <dt>Status</dt><dd>{$approval_request->status|escape:'html':'UTF-8'}</dd>
        <dt>Operation</dt><dd>{$approval_request->operation_type|escape:'html':'UTF-8'}</dd>
        <dt>Product target</dt><dd>{$product_target_display|escape:'html':'UTF-8'}</dd>
        <dt>Payload</dt><dd><pre>{$approval_request->payload_json|escape:'html':'UTF-8'}</pre></dd>
        <dt>Diff</dt><dd><pre>{$approval_request->diff_json|escape:'html':'UTF-8'}</pre></dd>
        <dt>Payload hash</dt><dd>{$approval_request->payload_hash|escape:'html':'UTF-8'}</dd>
        <dt>Creator employee ID</dt><dd>{if $approval_request->created_by_employee_id|intval === 0}API/Codex{else}{$approval_request->created_by_employee_id|intval}{/if}</dd>
        <dt>Created at</dt><dd>{$created_at_display|escape:'html':'UTF-8'}</dd>
        <dt>Expires at</dt><dd>{$expires_at_display|escape:'html':'UTF-8'}</dd>
    </dl>
</div>