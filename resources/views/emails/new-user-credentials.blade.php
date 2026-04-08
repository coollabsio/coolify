<x-emails.layout>
Hola {{ $name }},

Se ha creado una cuenta para ti en **{{ $instanceName }}**.

Estas son tus credenciales de acceso:

- **URL:** {{ $loginUrl }}
- **Email:** {{ $email }}
- **Contraseña:** {{ $password }}

Por seguridad, te recomendamos cambiar la contraseña una vez hayas iniciado sesión.

Si no esperabas este correo, ignóralo o contacta con el administrador.
</x-emails.layout>
