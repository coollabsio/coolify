<div>
    <x-database-status-info :database="$database" :label="$label" :db-url="$dbUrl" :db-url-public="$dbUrlPublic"
        :enable-ssl="$enableSsl" :ssl-mode="$sslMode" :certificate-valid-until="$certificateValidUntil"
        :supports-ssl="$supportsSsl" :ssl-mode-options="$sslModeOptions" :ssl-mode-helper="$sslModeHelper"
        :show-public-url-placeholder="$showPublicUrlPlaceholder" :is-exited="$isExited" />
</div>
