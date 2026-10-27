import io

p = 'config/sanctum.php'
with io.open(p, encoding='utf-8', newline='') as f:
    s = f.read()

old = """    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    */

    'expiration' => null,
"""

new = """    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    | Default: 60 minutes. Per-token expiry is still honoured via expires_at
    | set at issuance.
    */

    'expiration' => 60,
"""

s = s.replace(old, new)
if "'expiration' => null" in s and "'expiration' => 60" not in s:
    s = s.replace("'expiration' => null,", "'expiration' => 60,")

with io.open(p, 'w', encoding='utf-8', newline='') as f:
    f.write(s)

print('sanctum.php: expiration set to 60 minutes')
