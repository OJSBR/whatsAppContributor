# WhatsApp Contributor — OJS plugin

[![OJS](https://img.shields.io/badge/OJS-3.4%20%7C%203.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![Version](https://img.shields.io/badge/version-1.1.0.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

A generic plugin for **Open Journal Systems (OJS)** that adds a **Phone / WhatsApp** field
(E.164 format) to the contributor (author) form. It can be configured as optional or
required per journal. The number is stored in `author_settings` under the `whatsapp`
setting and is shown **only in the editorial forms** — it is not disclosed publicly.

> Developed and maintained by **[OJSBR](https://ojsbr.com.br)**.

## Compatibility / branches

| OJS version | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x   | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.1.0.0 |
| OJS 3.4.x   | [`stable-3_4_0`](../../tree/stable-3_4_0) | 1.0.0.0 |

## Installation

1. Download the release for your OJS version (or clone the matching branch).
2. Install via **Settings → Website → Plugins → Upload A New Plugin**, or extract the
   folder into `plugins/generic/` so you get `plugins/generic/whatsAppContributor/`.
3. Enable **WhatsApp Contributor Plugin** under the *Generic* plugins list.

## Configuration

In the plugin settings, choose whether the **Phone / WhatsApp** field is **required** when
registering a contributor for an article in the journal.

- Validation: **E.164** format (e.g. `+5511999999999`).
- Visibility: editorial forms only.
- Storage: `author_settings` with `setting_name = "whatsapp"`.

### OJS 3.5 note

Following PKP issue #11793, the hooks are always registered and the `getEnabled()` check
runs inside each callback, so the author schema is extended on every request (display,
save, API) and the `whatsapp` value is not silently dropped by the `SchemaDAO` save.

## Contributing

Issues and pull requests are welcome. Please target the branch matching the OJS version
you are working against.

## License

Distributed under the **GNU GPL v3**. See [`LICENSE`](LICENSE).

---

## 🇧🇷 Português

Plugin genérico para o **Open Journal Systems (OJS)** que adiciona um campo
**Telefone / WhatsApp** (formato E.164) ao formulário de contribuidor (autor). Pode ser
configurado como opcional ou obrigatório por revista. O número é gravado em
`author_settings` sob a chave `whatsapp` e aparece **apenas nos formulários editoriais** —
não é divulgado publicamente.

> Desenvolvido e mantido pela **[OJSBR](https://ojsbr.com.br)**.

### Compatibilidade / branches

| Versão do OJS | Branch | Release do plugin |
|---------------|--------|-------------------|
| OJS 3.5.x     | `stable-3_5_0` *(padrão)* | 1.1.0.0 |
| OJS 3.4.x     | `stable-3_4_0` | 1.0.0.0 |

### Instalação

Instale em **Configurações → Website → Plugins → Enviar um novo plugin**, ou extraia a
pasta em `plugins/generic/` (ficando `plugins/generic/whatsAppContributor/`). Depois ative
o **WhatsApp Contributor Plugin** na lista de plugins *Genéricos*.

### Configuração

Nas configurações do plugin, escolha se o campo **Telefone / WhatsApp** é **obrigatório**
ao cadastrar um contribuidor. Validação em **E.164** (ex.: `+5511999999999`); visível
apenas nos formulários editoriais.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE).
