# Enlil

Sistema de organización de proyectos (PHP + XML + JS) con administración única.

## Requisitos
- PHP 8+ con SQLite **no requerido** (se usa XML)
- Extensiones: `curl` recomendada
- Servidor web (Apache/Nginx)

## Instalación rápida
1. Apunta el docroot del dominio a este directorio.
2. Asegura permisos de escritura en `data/`.
3. Accede al dominio y crea el usuario administrador.

## Telegram
- Bot global se configura en **Panel**.
- Webhook: botón “Activar webhook” en el panel.
- Checklist nativo requiere Telegram Business conectado.

## Estructura
- `includes/` lógica PHP
- `assets/` estilos
- `data/` XML (no se versiona)

## Seguridad
- Los archivos XML y logs se ignoran en Git (ver `.gitignore`).
