# WhatsApp Contributor — OJS plugin (OJS 3.4 branch)

[![OJS](https://img.shields.io/badge/OJS-3.4-brightgreen)](https://pkp.sfu.ca/ojs/)
[![Version](https://img.shields.io/badge/version-1.0.0.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

> **This is the `stable-3_4_0` branch (OJS 3.4).** For OJS 3.5 use the
> [`stable-3_5_0`](../../tree/stable-3_5_0) branch.

A generic plugin for **Open Journal Systems (OJS)** that adds a **Phone / WhatsApp** field
(E.164 format) to the contributor (author) form. It can be configured as optional or
required per journal. The number is stored in `author_settings` under the `whatsapp`
setting and is shown **only in the editorial forms** — it is not disclosed publicly.

> Developed and maintained by **[OJSBR](https://ojsbr.com.br)**.

## Compatibility / branches

| OJS version | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x   | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.1.0.0 |
| OJS 3.4.x   | [`stable-3_4_0`](../../tree/stable-3_4_0) *(this branch)* | 1.0.0.0 |

## Installation

Install via **Settings → Website → Plugins → Upload A New Plugin**, or extract the folder
into `plugins/generic/` (giving `plugins/generic/whatsAppContributor/`). Then enable
**WhatsApp Contributor Plugin** under the *Generic* plugins list.

## Configuration

In the plugin settings, choose whether the **Phone / WhatsApp** field is **required** when
registering a contributor. Validation: **E.164** (e.g. `+5511999999999`); visible in
editorial forms only; stored in `author_settings` as `whatsapp`.

## License

Distributed under the **GNU GPL v3**. See [`LICENSE`](LICENSE).

---

## 🇧🇷 Português

> **Esta é a branch `stable-3_4_0` (OJS 3.4).** Para OJS 3.5 use a branch
> [`stable-3_5_0`](../../tree/stable-3_5_0).

Plugin genérico para o **Open Journal Systems (OJS)** que adiciona um campo
**Telefone / WhatsApp** (formato E.164) ao formulário de contribuidor (autor).
Configurável como opcional ou obrigatório por revista. O número é gravado em
`author_settings` sob a chave `whatsapp` e aparece **apenas nos formulários editoriais**.

> Desenvolvido e mantido pela **[OJSBR](https://ojsbr.com.br)**.

### Instalação

Instale em **Configurações → Website → Plugins → Enviar um novo plugin**, ou extraia a
pasta em `plugins/generic/` (ficando `plugins/generic/whatsAppContributor/`). Depois ative
o **WhatsApp Contributor Plugin** na lista de plugins *Genéricos*.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE).
