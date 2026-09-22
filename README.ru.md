<div align="center">

<img src="./public/coolify-logo.svg" alt="Coolify logo" width="120" />

# Coolify

**Открытая платформа для деплоя приложений, баз данных и сервисов на ваших серверах.**

Open source и бесплатно навсегда — см. нашу [philosophy](https://coolify.io/philosophy).

<p>
  <a href="README.md">English</a> ·
  <strong>Русский</strong>
</p>

![Latest Release Version](https://img.shields.io/badge/dynamic/json?labelColor=grey&color=6366f1&label=Latest%20released%20version&url=https%3A%2F%2Fcdn.coollabs.io%2Fcoolify%2Fversions.json&query=coolify.v4.version&style=for-the-badge
)

[Website](https://coolify.io) · [Documentation](https://coolify.io/docs) · [Cloud](https://app.coolify.io) · [Discord](https://coollabs.io/discord) · [Community](https://github.com/coollabsio/coolify/discussions)

</div>

## Что такое Coolify?

Coolify — открытая self-hostable альтернатива Heroku, Netlify и Vercel. Она помогает управлять серверами, приложениями и базами данных на вашем железе. Нужен только SSH.

Можно использовать VPS, bare-metal, Raspberry Pi или любой сервер с SSH. Coolify даёт удобство cloud-платформы, оставляя контроль инфраструктуры у вас.

Конфигурации остаются на ваших серверах. Если вы перестанете пользоваться Coolify, уже запущенные ресурсы продолжат работать и останутся управляемыми.

## Что умеет Coolify?

- **Deploy any application:** сборка из GitHub, GitLab, Bitbucket или Gitea через Nixpacks, Railpack, Dockerfile, Docker Compose или готовый Docker image.
- **Run databases and services:** управляемые БД и 300+ one-click сервисов с persistent storage и сгенерированными credentials.
- **Automate deployments:** деплой на каждый Git push, preview для pull request, deployment webhooks, rollback к сохранённым образам.
- **Manage networking:** custom domains, автоматические HTTPS-сертификаты, reverse proxies, health checks и container networks.
- **Operate your infrastructure:** несколько серверов, логи деплоя и runtime, terminal в контейнерах, мониторинг ресурсов.
- **Protect your workloads:** бэкапы БД и storage, scheduled tasks, env vars, secrets и notifications.
- **Integrate with your workflow:** dashboard, API, CLI, MCP и team-based access controls.

## Quick start

Установка Coolify на поддерживаемый сервер одной командой:

```bash
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
```

См. [installation guide](https://coolify.io/docs/installation) для требований и деталей. Можно заранее посмотреть [installation script](./scripts/install.sh).

## Self-hosted или Cloud

| Self-hosted | Coolify Cloud |
| --- | --- |
| Установите Coolify на свой сервер. | Используйте инстанс, который поддерживаем мы. |
| Полный контроль и обслуживание платформы. | Высокая доступность и меньше maintenance. |
| Бесплатно и open source. | Платный сервис с email notifications и доп. поддержкой. |

Рекомендуемый self-hosted setup: один сервер под Coolify и один или несколько — под деплои. Если не хотите обслуживать сервер Coolify — [Coolify Cloud](https://app.coolify.io). Актуальные цены: [coolify.io](https://coolify.io).

## Community and support

- Документация: [documentation](https://coolify.io/docs).
- Сообщество: [Discord](https://coollabs.io/discord).
- Вопросы: [GitHub Discussions](https://github.com/coollabsio/coolify/discussions).
- Перед PR: [contribution guide](./CONTRIBUTING.md).
- [Code of conduct](./CODE_OF_CONDUCT.md).
- Контакты: [support page](https://coolify.io/docs/contact).

## Donations

Чтобы оставаться полностью бесплатными и open-source без paywall-фич и развивать проект дальше, нам нужна ваша помощь. Если Coolify вам нравится — рассмотрите донат.

[coolify.io/sponsorships](https://coolify.io/sponsorships)

Спасибо!

### Huge Sponsors

* [CubePath](https://cubepath.com/coolify) - Premium dedicated servers and cloud VPS hosting
* [Context.dev](https://www.context.dev/) - Web scraping API for AI agents
* [Ginernet](https://ginernet.com/) - Hosting powerful servers in Spain
* [SerpAPI](https://serpapi.com) - Google Search API
* [MVPS](https://www.mvps.net) - Cheap VPS servers at the highest possible quality
* [ScreenshotOne](https://screenshotone.com) - Screenshot API for devs
* [PrivateAlps](https://privatealps.net) - Cloud Services Provider focused on privacy and control
* [Seibert Group](https://seibert.link/coolifysoftware) - AI agents like Claude Code for company-wide productivity
* [Contabo](https://contabo.com/en/coolify-vps/) - Cloud VPS & dedicated servers

### Big Sponsors

* [Vanaways](https://www.vanaways.co.uk) · [Cloudways](https://www.cloudways.com/en/?id=2125302) · [ByteBase](https://www.bytebase.com) · [Ramnode](https://ramnode.com/) · [23M](https://23m.com) · [Macarne](https://macarne.com) · [Hetzner](http://htznr.li/CoolifyXHetzner) · [Logto](https://logto.io) · [Supadata](https://supadata.ai/) · [Tolgee](https://tolgee.io) · [Best Consultant](https://bc.direct) · [ArcJet](https://arcjet.com) · [SupaGuide](https://supa.guide) · [CodeRabbit](https://coderabbit.ai) · [Convex](https://convex.link/coolify.io) · [GoldenVM](https://billing.goldenvm.com) · [Hostinger](https://www.hostinger.com/vps/coolify-hosting) · [Ubicloud](https://www.ubicloud.com) · [Greptile](https://www.greptile.com) · [VPSDime](https://vpsdime.com/) и многие другие — полный список в английском [README.md](README.md).

…и ещё больше на [GitHub Sponsors](https://github.com/sponsors/coollabsio)

## Star History

[![Star History Chart](https://api.star-history.com/svg?repos=coollabsio/coolify&type=Date)](https://star-history.com/#coollabsio/coolify&Date)
