<div>
    <h1>Create a new Service</h1>
    <div class="pb-4">You can deploy complex services easily with Docker Compose.</div>
    <form wire:submit="submit">
        <div class="flex gap-2 pb-1">
            <h2>Docker Compose</h2>
            <x-forms.button type="submit">Save</x-forms.button>
        </div>
        <x-forms.textarea label="Docker Compose file"
            helper="
    You can use these variables in your Docker Compose file and Coolify will generate default values or replace them with the values you set on the UI forms.<br>
    <br>
    - SERVICE_FQDN_*: FQDN - could be changeable from the UI. (example: SERVICE_FQDN_GHOST)<br>
    - SERVICE_URL_*: URL parsed from FQDN - could be changeable from the UI. (example: SERVICE_URL_GHOST)<br>
    - SERVICE_BASE64_64_*: Generated 'base64' string with length of '64' (example: SERVICE_BASE64_64_GHOST, to generate 32 bit: SERVICE_BASE64_32_GHOST)<br>
    - SERVICE_USER_*: Generated user (example: SERVICE_USER_MYSQL)<br>
    - SERVICE_PASSWORD_*: Generated password (example: SERVICE_PASSWORD_MYSQL)<br>"
            rows="20" id="dockerComposeRaw"
            placeholder='services:
  ghost:
    documentation: https://ghost.org/docs/config
    image: ghost:5
    volumes:
      - ghost-content-data:/var/lib/ghost/content
    environment:
      - url=$SERVICE_FQDN_GHOST
      - database__client=mysql
      - database__connection__host=mysql
      - database__connection__user=$SERVICE_USER_MYSQL
      - database__connection__password=$SERVICE_PASSWORD_MYSQL
      - database__connection__database=${MYSQL_DATABASE-ghost}
    ports:
      - "2368"
    depends_on:
      - mysql
  mysql:
    documentation: https://hub.docker.com/_/mysql
    image: mysql:8.0
    volumes:
      - ghost-mysql-data:/var/lib/mysql
    environment:
      - MYSQL_USER=${SERVICE_USER_MYSQL}
      - MYSQL_PASSWORD=${SERVICE_PASSWORD_MYSQL}
      - MYSQL_DATABASE=${MYSQL_DATABASE}
      - MYSQL_ROOT_PASSWORD=${SERVICE_PASSWORD_MYSQL_ROOT}
'></x-forms.textarea>
        {{-- <x-forms.textarea label="Environment File" rows="20" id="envFile"></x-forms.textarea> --}}
    </form>
</div>
