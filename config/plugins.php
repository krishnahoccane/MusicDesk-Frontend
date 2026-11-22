<?php

/*
|--------------------------------------------------------------------------
| LabelDesk add-on (plugin) packages
|--------------------------------------------------------------------------
| Add-on releases are ZIPs signed with an Ed25519 key whose public half is
| compiled into LabelDesk (LicenseAuthority::PLUGIN_PUBLIC_KEY). Uploads are
| either already signed (LabelDesk's scripts/build-plugin.php) and verified
| against public_key, or signed here with the secret key file. The key file
| holds the base64 secret key; keep it out of git and backups you share.
*/
return [
    'public_key' => env('PLUGIN_PUBLIC_KEY', 'ccJGVU14pvwFlkYX0R1D3amoXck0gvvwiRDGiSf65qc='),

    'signing_key_path' => env('PLUGIN_SIGNING_KEY_PATH', storage_path('app/private/plugin-signing.key')),
];
