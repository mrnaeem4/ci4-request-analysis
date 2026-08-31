# Wazuh integration

This library writes one JSON Line per request to
`writable/logs/analysis.log`. Wazuh can monitor this file in real time with a
JSON decoder and alert on suspicious request patterns.

```
CI4 app  ──►  writable/logs/analysis.log (JSONL)
                    │
        Wazuh Agent (localfile, log_format=json)
                    │
        Wazuh Manager (decoder + rules) ──► alerts
```

> **Important**: Wazuh reads plain-text files only. The active `analysis.log`
> is tailed continuously; rotated files are gzip-compressed (`analysis-*.log.gz`)
> and are **not** read directly by Wazuh. See
> [Handling rotated/compressed logs](#handling-rotatedcompressed-logs).

---

## 1. Agent configuration

On the host that runs the CI4 application, edit `/var/ossec/etc/ossec.conf` and
add a `<localfile>` entry inside `<ossec_config>`:

```xml
<ossec_config>

  <!-- Active log (real time) -->
  <localfile>
    <log_format>json</log_format>
    <location>/var/www/app/writable/logs/analysis.log</location>
  </localfile>

  <!-- Rotated, still-uncompressed files (optional) -->
  <localfile>
    <log_format>json</log_format>
    <location>/var/www/app/writable/logs/analysis-*.log</location>
  </localfile>

</ossec_config>
```

Replace `/var/www/app` with the actual path of your CI4 application. The
`writable/logs` directory must be readable by the `wazuh` user.

Apply the change:

```bash
systemctl restart wazuh-agent
# or
/var/ossec/bin/wazuh-control restart
```

### Handling rotated/compressed logs

Wazuh's `localfile` cannot decompress gzip. If you also need historical
rotated entries ingested, decompress them before they are consumed, e.g. via
cron (the `.gz` originals are kept for retention):

```bash
#!/bin/bash
for f in /var/www/app/writable/logs/analysis-*.log.gz; do
    [ -e "$f" ] || continue
    gunzip -k "$f"
done
```

Or simply rely on real-time monitoring of `analysis.log`, which is the default
and recommended setup.

---

## 2. Manager decoder

On the Wazuh manager, create `/var/ossec/etc/decoders/local_decoder.xml`:

```xml
<!-- Decodes the CI4 request-analysis JSONL envelope -->
<decoder name="ci4_request_analysis">
  <prematch>^\{"log_data":</prematch>
  <plugin_decoder>JSON_Decoder</plugin_decoder>
</decoder>
```

The `<plugin_decoder>JSON_Decoder</plugin_decoder>` decoder turns every JSON key into a dynamic field
prefixed with `data.`, e.g. `data.log_data.method`, `data.log_data.srcip`,
`data.created_at`. See the [field reference](#json-field-reference) below.

---

## 3. Manager rules

Create `/var/ossec/etc/rules/local_rules.xml`:

```xml
<group name="ci4_request_analysis,">

  <!-- Base rule: any request-analysis entry -->
  <rule id="100200" level="0">
    <decoded_as>ci4_request_analysis</decoded_as>
    <description>CodeIgniter request analysis log entry</description>
    <group>ci4_request_analysis,</group>
  </rule>

  <!-- Suspicious file extension uploaded (.php, .phar, double extension, ...) -->
  <rule id="100201" level="10">
    <if_sid>100200</if_sid>
    <regex>\.(php|php3|php4|php5|php7|phps|phar|pht|phtml|shtml|htaccess|inc)["\}\]\.]</regex>
    <description>CI4 request analysis: suspicious file extension uploaded</description>
    <group>ci4_request_analysis,attack,</group>
  </rule>

  <!-- Any file upload -->
  <rule id="100202" level="5">
    <if_sid>100200</if_sid>
    <regex>"file_count":[^0-9]*[1-9]</regex>
    <description>CI4 request analysis: file upload detected</description>
    <group>ci4_request_analysis,</group>
  </rule>

  <!-- Request body was truncated (very large payload) -->
  <rule id="100203" level="7">
    <if_sid>100200</if_sid>
    <regex>\[truncated\]</regex>
    <description>CI4 request analysis: request body truncated (large payload)</description>
    <group>ci4_request_analysis,</group>
  </rule>

  <!-- Redacted sensitive fields -->
  <rule id="100204" level="3">
    <if_sid>100200</if_sid>
    <regex>\*\*\*REDACTED\*\*\*</regex>
    <description>CI4 request analysis: request contained sensitive fields (redacted)</description>
    <group>ci4_request_analysis,</group>
  </rule>

  <!-- SQL injection patterns in request body -->
  <rule id="100205" level="12">
    <if_sid>100200</if_sid>
    <regex>\b(?:UNION\s+(?:ALL\s+)?SELECT|DROP\s+TABLE|INSERT\s+INTO|DELETE\s+FROM|ALTER\s+TABLE|CREATE\s+TABLE|EXEC\s+(?:xp_|sp_)|'?\s*OR\s+'1'\s*=\s*'1|1'\s*OR\s+'1'\s*=\s*'1|'\s*OR\s+1\s*=\s*1\s*--|OR\s+1\s*=\s*1\s*#)</regex>
    <description>CI4 request analysis: possible SQL injection in request body</description>
    <group>ci4_request_analysis,attack,sql_injection,</group>
  </rule>

</group>
```

> Rule IDs `100200`–`100206` use the local rule range (`100000`–`199999`).
> Adjust IDs if they collide with other local rules.
>
> `<regex>` matches against the full flattened JSON line, so tune the patterns
> to avoid false positives (e.g. a request body that legitimately contains
> `.php`). The SQL injection patterns are deliberately strict (combined
> keywords/operators) but should still be validated against your own traffic.

### Custom rules with JSON fields

For exact matches against a parsed field, use `<field name="data.…">`:

```xml
<rule id="100206" level="6">
  <if_sid>100200</if_sid>
  <field name="data.log_data.method">DELETE</field>
  <description>CI4 request analysis: DELETE request</description>
</rule>
```

---

## 4. Apply and verify

Restart the manager so the decoder/rules take effect:

```bash
systemctl restart wazuh-manager
# or
/var/ossec/bin/wazuh-control restart
```

Test a sample log line against the decoder and rules (on the manager):

```bash
echo '{"log_data":{"timestamp":"2026-08-31T02:15:04+00:00","domain":"app.example.com","path":"/api/profile/update","method":"POST","srcip":"203.0.113.10","user_agent":"Mozilla/5.0","query_string":"","headers":{"Content-Type":"application/json"},"raw_body":"{\"name\":\"User\",\"password\":\"***REDACTED***\"}","file_count":0,"file_names":[],"file_metadata":[]},"retry_count":0,"last_attempt":null,"created_at":"2026-08-31T02:15:04+00:00"}' \
  | /var/ossec/bin/wazuh-logtest
```

Watch alerts:

```bash
tail -f /var/ossec/logs/alerts/alerts.json
```

---

## JSON field reference

| Dynamic field | Source | Example |
|---|---|---|
| `data.log_data.timestamp` | request time (ISO 8601 UTC) | `2026-08-31T02:15:04+00:00` |
| `data.log_data.domain` | tenant subdomain (`HTTP_HOST`) | `app.example.com` |
| `data.log_data.path` | request URI path | `/api/profile/update` |
| `data.log_data.method` | HTTP method | `POST` |
| `data.log_data.srcip` | client IP | `203.0.113.10` |
| `data.log_data.user_agent` | User-Agent | `Mozilla/5.0 …` |
| `data.log_data.query_string` | query string | `page=1` |
| `data.log_data.headers` | request headers (object) | `{"Content-Type":"application/json"}` |
| `data.log_data.raw_body` | redacted/truncated body (JSON string) | `{"name":"User",…}` |
| `data.log_data.file_count` | number of uploaded files | `0` |
| `data.log_data.file_names` | uploaded file names (array) | `["shell.php.jpg"]` |
| `data.log_data.file_metadata` | upload metadata (array of objects) | `[{…}]` |
| `data.created_at` | write time | `2026-08-31T02:15:04+00:00` |
| `data.retry_count` | always `0` (legacy envelope) | `0` |
| `data.last_attempt` | always `null` (legacy envelope) | `null` |
