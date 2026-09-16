<?php

/*
|--------------------------------------------------------------------------
| Cloudinary overrides
|--------------------------------------------------------------------------
|
| CloudinaryServiceProvider::register() merges the package's own config file
| into the "cloudinary" key, so only the values this application overrides
| need to be declared here — the credentials keep coming from CLOUDINARY_URL
| via the package defaults and are deliberately not repeated.
|
*/

return [

    /*
    | Seconds an upload request to Cloudinary may take before it is abandoned
    | and the calling controller falls back to the local public disk.
    |
    | The Cloudinary SDK's own default is 60 seconds (ApiConfig::DEFAULT_TIMEOUT).
    | Uploads here are synchronous and inside the request, so one unreachable
    | Cloudinary could hold a PHP worker — and every request queued behind it —
    | for a full minute. Ten seconds is comfortably above a healthy upload while
    | keeping a failure cheap.
    */
    'upload_timeout' => env('CLOUDINARY_UPLOAD_TIMEOUT', 10),

    /*
    | Lifetime, in seconds, of a signed download URL minted for a medical file
    | held on Cloudinary (App\Services\MedicalFileStorage).
    |
    | Medical uploads use delivery type "authenticated", so the asset's own URL
    | returns 401 and the only way in is a signature this application issues
    | after authorizing the request. That signature travels to the browser as a
    | redirect target, which means it can be copied out of history or a referrer
    | — so it is deliberately short-lived. Five minutes is comfortably longer
    | than a redirect-and-fetch (including a large scan on a slow connection)
    | while keeping a leaked URL close to worthless; a user who needs the file
    | again simply requests it again and gets a fresh signature.
    */
    'signed_url_ttl' => env('CLOUDINARY_SIGNED_URL_TTL', 300),

];
