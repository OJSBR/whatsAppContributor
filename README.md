# WhatsApp Contributor — OJS plugin

[![OJS](https://img.shields.io/badge/OJS-3.4%20%7C%203.5-brightgreen)](https://pkp.sfu.ca/ojs/)
[![Version](https://img.shields.io/badge/version-1.2.0.0-blue)](version.xml)
[![License](https://img.shields.io/badge/license-GPL--3.0-lightgrey)](LICENSE)

**⬇️ Install package:** [OJS 3.5](https://github.com/OJSBR/whatsAppContributor/releases/download/1.2.0.0/whatsAppContributor-1.2.0.0.tar.gz) · [OJS 3.4](https://github.com/OJSBR/whatsAppContributor/releases/download/1.2.0.0-ojs3.4/whatsAppContributor-1.2.0.0-ojs3.4.tar.gz) — or browse all [Releases](../../releases).

A generic plugin for **Open Journal Systems (OJS)** that adds a **Phone / WhatsApp** field
(E.164 format) to the contributor (author) form and, if the journal wants, to the user
registration form. The submitter's number is carried to their own authorship, the way the core
carries the ORCID iD. The number is shown **only in editorial forms** — it is not disclosed
publicly.

> **Developed and maintained by [OJSBR](https://ojsbr.com).** See the
> [Credits & authorship](#credits--authorship) section below.

## Compatibility & branches

| OJS version | Branch | Plugin release |
|-------------|--------|----------------|
| OJS 3.5.x   | [`stable-3_5_0`](../../tree/stable-3_5_0) *(default)* | 1.2.0.0 |
| OJS 3.4.x   | [`stable-3_4_0`](../../tree/stable-3_4_0) | 1.2.0.0 |

Both branches ship the same code; the locale folders follow each OJS line (38 languages).

## The problem

Editorial teams reach authors by phone and WhatsApp far more than by e-mail, but OJS has no
place for the number of a contributor. Asking for it by e-mail after the fact means missing
numbers and numbers in every possible format.

## What it does

- **Contributor form:** a *Phone / WhatsApp* field, optional or required per journal, validated
  as E.164 (`+5511999999999`), with a message that shows the expected format.
- **Registration form (optional):** the same field on the user registration page. It is saved
  as the **phone of the account** — the field the user already edits under *Profile → Contact*
  — after removing spaces, hyphens, dots and parentheses and turning a leading `00` into `+`.
  It is required there when the journal requires the number from contributors.
- **Authorship:** when a user becomes an author of their own submission, a valid E.164 phone on
  the account fills the author's number (`Author::newAuthorFromUser`, the hook the core uses
  for the ORCID iD). A phone without country code is left out rather than stored wrong.

## Installation

1. Install via **Settings → Website → Plugins → Upload A New Plugin**, or extract the folder
   into `plugins/generic/` so that you get `plugins/generic/whatsAppContributor/`.
   Do not rename the folder: OJS derives the plugin's class namespace from the directory name.
2. Enable **WhatsApp Contributor Plugin** in the *Generic* plugins list.

## Configuration

In the plugin **Settings**:

- **Make the field required for all contributors** (off by default);
- **Also ask for it on the user registration form** (off by default).

## How it works (technical)

- The author schema gains `whatsapp` (string, nullable, E.164 regex) on every request, so the
  schema DAO never drops a stored number; the number is stored in `author_settings`.
- `Form::config::before` adds the field to the `contributor` form; `Author::validate` replaces
  the generic format error; `Author::newAuthorFromUser` copies the account phone.
- The registration template has no hook: `registrationform::display` registers an output filter
  that adds the field at the end of `fieldset.identity`, once;
  `registrationform::Constructor`, `::readUserVars` and `::execute` validate, read and store
  the number on the new account.

## Tests

- **PHP suite** (`tests/`, 20 tests): the classes against the installed PKP, E.164
  normalization and validation, the schema property, the registration field (placement,
  escaping, required marker) and the 38 translations. Run either way from the OJS root:

  ```bash
  php plugins/generic/whatsAppContributor/tests/run.php
  lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml --no-coverage "$PWD/plugins/generic/whatsAppContributor/tests"
  ```

- **Cypress** (`cypress/tests/functional/WhatsAppContributor.cy.js`): the registration field
  following the settings (absent, required, optional), and a contributor saved through the REST
  endpoints the contributor form uses (a malformed number refused with the format message, an
  E.164 number stored). Captcha on login must be off for the run.
- Verified on OJS 3.5.0.3 and 3.4.0.10: the registration form's validation and the phone saved
  on the account, the phone carried to the author created from the user, and the Cypress spec
  green on both.

## Credits & authorship

- **Developed and maintained by** [OJSBR](https://ojsbr.com) — original plugin.
- Distributed under the **GNU GPL v3**.

## Contributing

Issues and pull requests are welcome. Please target the branch matching the OJS version you are
working against. See [`CONTRIBUTING.md`](CONTRIBUTING.md).

## License

Distributed under the **GNU GPL v3**. See [`LICENSE`](LICENSE) and `docs/COPYING`.

---

## 🇧🇷 Português

Plugin genérico para o **Open Journal Systems (OJS)** que adiciona um campo
**Telefone / WhatsApp** (formato E.164) ao formulário de contribuidor (autor) e, se a revista
quiser, ao formulário de cadastro de usuário. O número de quem submete é levado para a própria
autoria, do mesmo jeito que o núcleo leva o ORCID. O número aparece **apenas nos formulários
editoriais** — não é divulgado publicamente.

> **Desenvolvido e mantido pela [OJSBR](https://ojsbr.com).** Veja a seção
> [Créditos e autoria](#créditos-e-autoria) abaixo.

### Compatibilidade e branches

| Versão do OJS | Branch | Release do plugin |
|---------------|--------|-------------------|
| OJS 3.5.x     | [`stable-3_5_0`](../../tree/stable-3_5_0) *(padrão)* | 1.2.0.0 |
| OJS 3.4.x     | [`stable-3_4_0`](../../tree/stable-3_4_0) | 1.2.0.0 |

As duas branches têm o mesmo código; as pastas de idioma seguem cada linha do OJS (38 idiomas).

### O que faz

- **Formulário de contribuidor:** campo *Telefone / WhatsApp*, opcional ou obrigatório por
  revista, validado em E.164 (`+5511999999999`), com mensagem mostrando o formato esperado.
- **Cadastro de usuário (opcional):** o mesmo campo na página de cadastro. Fica salvo como
  **telefone da conta** — o campo que o usuário já edita em *Perfil → Contato* —, depois de tirar
  espaços, hífens, pontos e parênteses e trocar um `00` inicial por `+`. É obrigatório ali quando
  a revista exige o número dos contribuidores.
- **Autoria:** quando o usuário vira autor da própria submissão, um telefone válido em E.164 na
  conta preenche o número do autor (hook `Author::newAuthorFromUser`, o mesmo que o núcleo usa
  para o ORCID). Telefone sem código do país fica de fora em vez de ser gravado errado.

### Instalação

Instale em **Configurações → Website → Plugins → Enviar um novo plugin**, ou extraia a pasta em
`plugins/generic/` (ficando `plugins/generic/whatsAppContributor/`); não renomeie a pasta. Depois
ative o **WhatsApp Contributor Plugin** na lista de plugins *Genéricos*.

### Configuração

Nas **Configurações** do plugin: **tornar o campo obrigatório para todos os contribuidores** e
**pedir também no formulário de cadastro de usuário** (ambos desligados por padrão).

### Testes

Suíte PHP em `tests/` (20 testes, pelo `tests/run.php` ou pelo PHPUnit do PKP) e Cypress em
`cypress/tests/functional/`. Verificado no OJS 3.5.0.3 e 3.4.0.10: validação e gravação do
telefone no cadastro, telefone levado ao autor criado a partir do usuário, campo seguindo as
configurações e contribuidor gravado pela API com mensagem de formato.

### Créditos e autoria

- **Desenvolvido e mantido pela** [OJSBR](https://ojsbr.com) — plugin autoral.
- Distribuído sob a **GNU GPL v3**.

### Licença

Distribuído sob a **GNU GPL v3**. Veja [`LICENSE`](LICENSE) e `docs/COPYING`.
