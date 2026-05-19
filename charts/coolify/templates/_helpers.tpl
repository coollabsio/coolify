{{- define "coolify.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{- define "coolify.fullname" -}}
{{- if .Values.fullnameOverride -}}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" -}}
{{- else -}}
{{- $name := default .Chart.Name .Values.nameOverride -}}
{{- if contains $name .Release.Name -}}
{{- .Release.Name | trunc 63 | trimSuffix "-" -}}
{{- else -}}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" -}}
{{- end -}}
{{- end -}}
{{- end -}}

{{- define "coolify.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" -}}
{{- end -}}

{{- define "coolify.labels" -}}
helm.sh/chart: {{ include "coolify.chart" . }}
app.kubernetes.io/name: {{ include "coolify.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
{{- end -}}

{{- define "coolify.selectorLabels" -}}
app.kubernetes.io/name: {{ include "coolify.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end -}}

{{- define "coolify.componentLabels" -}}
{{ include "coolify.selectorLabels" .root }}
app.kubernetes.io/component: {{ .component }}
{{- end -}}

{{- define "coolify.serviceAccountName" -}}
{{- if .Values.serviceAccount.create -}}
{{- default (include "coolify.fullname" .) .Values.serviceAccount.name -}}
{{- else -}}
{{- default "default" .Values.serviceAccount.name -}}
{{- end -}}
{{- end -}}

{{- define "coolify.envSecretName" -}}
{{- if .Values.env.existingSecret -}}
{{- .Values.env.existingSecret -}}
{{- else -}}
{{- default "coolify-env" .Values.env.secretName -}}
{{- end -}}
{{- end -}}

{{- define "coolify.dataClaimName" -}}
{{- default (printf "%s-data" (include "coolify.fullname" .)) .Values.persistence.existingClaim -}}
{{- end -}}

{{- define "coolify.image" -}}
{{- $tag := default .Chart.AppVersion .Values.image.tag -}}
{{- printf "%s/%s:%s" .Values.image.registry .Values.image.repository $tag -}}
{{- end -}}

{{- define "coolify.realtimeImage" -}}
{{- printf "%s/%s:%s" .Values.realtimeImage.registry .Values.realtimeImage.repository .Values.realtimeImage.tag -}}
{{- end -}}

{{- define "coolify.databaseHost" -}}
{{- if .Values.postgresql.enabled -}}
{{- printf "%s.%s.svc.cluster.local" .Values.postgresql.fullnameOverride .Release.Namespace -}}
{{- else -}}
{{- required "database.host is required when postgresql.enabled=false" .Values.database.host -}}
{{- end -}}
{{- end -}}

{{- define "coolify.redisHost" -}}
{{- if .Values.redis.enabled -}}
{{- printf "%s-master.%s.svc.cluster.local" .Values.redis.fullnameOverride .Release.Namespace -}}
{{- else -}}
{{- required "redisConnection.host is required when redis.enabled=false" .Values.redisConnection.host -}}
{{- end -}}
{{- end -}}

{{- define "coolify.secretValue" -}}
{{- $root := .root -}}
{{- $name := .name -}}
{{- $key := .key -}}
{{- $value := default "" .value -}}
{{- $generated := default "" .generated -}}
{{- $existing := lookup "v1" "Secret" $root.Release.Namespace $name -}}
{{- if and $existing $existing.data (hasKey $existing.data $key) -}}
{{- index $existing.data $key | b64dec -}}
{{- else if ne $value "" -}}
{{- $value -}}
{{- else -}}
{{- $generated -}}
{{- end -}}
{{- end -}}

{{- define "coolify.commonEnv" -}}
- name: APP_ENV
  value: {{ .Values.app.env | quote }}
- name: DB_HOST
  value: {{ include "coolify.databaseHost" . | quote }}
- name: DB_PORT
  value: {{ .Values.database.port | quote }}
- name: REDIS_HOST
  value: {{ include "coolify.redisHost" . | quote }}
- name: REDIS_PORT
  value: {{ .Values.redisConnection.port | quote }}
- name: PHP_MEMORY_LIMIT
  value: {{ .Values.web.php.memoryLimit | quote }}
- name: PHP_FPM_PM_CONTROL
  value: {{ .Values.web.php.fpmPmControl | quote }}
- name: PHP_FPM_PM_START_SERVERS
  value: {{ .Values.web.php.fpmPmStartServers | quote }}
- name: PHP_FPM_PM_MIN_SPARE_SERVERS
  value: {{ .Values.web.php.fpmPmMinSpareServers | quote }}
- name: PHP_FPM_PM_MAX_SPARE_SERVERS
  value: {{ .Values.web.php.fpmPmMaxSpareServers | quote }}
{{- if not .Values.env.existingSecret }}
- name: APP_KEY
  valueFrom:
    secretKeyRef:
      name: {{ include "coolify.envSecretName" . }}
      key: APP_KEY
- name: DB_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ default (include "coolify.envSecretName" .) .Values.database.existingSecret.name }}
      key: {{ ternary .Values.database.existingSecret.key "DB_PASSWORD" (ne .Values.database.existingSecret.name "") }}
- name: REDIS_PASSWORD
  valueFrom:
    secretKeyRef:
      name: {{ default (include "coolify.envSecretName" .) .Values.redisConnection.existingSecret.name }}
      key: {{ ternary .Values.redisConnection.existingSecret.key "REDIS_PASSWORD" (ne .Values.redisConnection.existingSecret.name "") }}
- name: PUSHER_APP_ID
  valueFrom:
    secretKeyRef:
      name: {{ include "coolify.envSecretName" . }}
      key: PUSHER_APP_ID
- name: PUSHER_APP_KEY
  valueFrom:
    secretKeyRef:
      name: {{ include "coolify.envSecretName" . }}
      key: PUSHER_APP_KEY
- name: PUSHER_APP_SECRET
  valueFrom:
    secretKeyRef:
      name: {{ include "coolify.envSecretName" . }}
      key: PUSHER_APP_SECRET
{{- end }}
{{- range $name, $value := .Values.env.extra }}
- name: {{ $name }}
  value: {{ $value | quote }}
{{- end }}
{{- end -}}

{{- define "coolify.envVolume" -}}
- name: env-file
  secret:
    secretName: {{ include "coolify.envSecretName" . }}
    items:
      - key: .env
        path: .env
{{- end -}}

{{- define "coolify.dataVolume" -}}
- name: coolify-data
{{- if .Values.persistence.enabled }}
  persistentVolumeClaim:
    claimName: {{ include "coolify.dataClaimName" . }}
{{- else }}
  emptyDir: {}
{{- end }}
{{- end -}}

{{- define "coolify.volumeMounts" -}}
- name: env-file
  mountPath: /var/www/html/.env
  subPath: .env
  readOnly: true
- name: coolify-data
  mountPath: /var/www/html/storage/app/ssh
  subPath: ssh
- name: coolify-data
  mountPath: /var/www/html/storage/app/applications
  subPath: applications
- name: coolify-data
  mountPath: /var/www/html/storage/app/databases
  subPath: databases
- name: coolify-data
  mountPath: /var/www/html/storage/app/services
  subPath: services
- name: coolify-data
  mountPath: /var/www/html/storage/app/backups
  subPath: backups
{{- end -}}

{{- define "coolify.storageInitContainer" -}}
{{- if and .Values.persistence.enabled .Values.storageInit.enabled }}
- name: storage-init
  image: {{ printf "%s/%s:%s" .Values.storageInit.image.registry .Values.storageInit.image.repository .Values.storageInit.image.tag | quote }}
  imagePullPolicy: {{ .Values.storageInit.image.pullPolicy }}
  command:
    - /bin/sh
    - -ec
  args:
    - mkdir -p /data/ssh /data/applications /data/databases /data/services /data/backups && chown -R 9999:9999 /data
  securityContext:
    {{- toYaml .Values.storageInit.securityContext | nindent 4 }}
  volumeMounts:
    - name: coolify-data
      mountPath: /data
{{- end }}
{{- end -}}
