/**
 * @file cypress/tests/functional/WhatsAppContributor.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests: the settings, the registration field, and the number of a
 * contributor saved through the REST endpoints the contributor form uses.
 *
 * Parameters (--env): contextPath; adminUser, adminPassword (a journal manager;
 * captcha on login must be off for the run); submissionId, publicationId and
 * authorUserGroupId for the contributor test, skipped without them. The plugin
 * must be enabled. Settings touched and contributors added are restored or
 * deleted at the end. Assertions use names, ids and API data, never labels.
 */

describe('WhatsApp Contributor plugin', function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';
	const submissionId = Cypress.env('submissionId');
	const publicationId = Cypress.env('publicationId');
	const authorUserGroupId = Cypress.env('authorUserGroupId');

	const url = (path) => '/index.php/' + contextPath + '/' + path;
	const settingsForm = 'form[id="whatsAppContributorSettings"]';
	let original = null;
	let csrfToken = null;

	const login = () => {
		cy.clearCookies();
		cy.visit(url('login'));
		cy.get('input[id=username]').clear().type(adminUser, {delay: 0});
		cy.get('input[id=password]').clear().type(adminPassword, {delay: 0, log: false});
		cy.get('form[id=login] button').click();
		cy.get('form[id=login]', {timeout: 30000}).should('not.exist');
	};

	const openSettings = () => {
		cy.visit(url('management/settings/website'));
		cy.get('button[id="plugins-button"]', {timeout: 60000}).click();
		cy.waitJQuery();
		cy.get('tr[id*="whatsappcontributorplugin"] a.show_extras', {timeout: 30000}).click();
		cy.get('a[id*="whatsappcontributorplugin-settings"]', {timeout: 30000}).click();
		cy.waitJQuery();
		cy.get(settingsForm, {timeout: 30000}).should('exist');
	};

	const save = (required, registration) => {
		cy.get(settingsForm + ' input[name="whatsappRequired"]')[required ? 'check' : 'uncheck']({force: true});
		cy.get(settingsForm + ' input[name="showOnRegistration"]')[registration ? 'check' : 'uncheck']({force: true});
		cy.get(settingsForm + ' button[id^="submitFormButton-"]').click({force: true});
		cy.waitJQuery();
		cy.get(settingsForm, {timeout: 15000}).should('not.exist');
	};

	const registration = () => cy.visit(url('user/register'), {headers: {Cookie: 'OJSSID=cypress' + Date.now()}});

	describe('Registration', function() {
		it('Asks for the number on the registration form only when configured, required when required', function() {
			login();
			openSettings();
			cy.get(settingsForm + ' input[name="whatsappRequired"]').then(($required) => {
				cy.get(settingsForm + ' input[name="showOnRegistration"]').then(($registration) => {
					original = {required: $required.is(':checked'), registration: $registration.is(':checked')};
				});
			});

			save(false, false);
			cy.clearCookies();
			registration();
			cy.get('form#register input[name="whatsapp"]').should('not.exist');

			login();
			openSettings();
			save(true, true);
			cy.clearCookies();
			registration();
			cy.get('form#register fieldset.identity input[type="tel"][name="whatsapp"]').should('have.attr', 'required');
			cy.get('form#register #whatsAppContributorDescription').invoke('text').should('match', /\S/).and('not.contain', '##');

			login();
			openSettings();
			save(false, true);
			cy.clearCookies();
			registration();
			cy.get('form#register input[name="whatsapp"]').should('not.have.attr', 'required');
		});

		after(function() {
			if (original) {
				login();
				openSettings();
				save(original.required, original.registration);
			}
		});
	});

	describe('Contributors', function() {
		const added = [];
		const api = () => url('api/v1/submissions/' + submissionId + '/publications/' + publicationId + '/contributors');
		const request = (method, path, body) => cy.request({method, url: api() + path, body, headers: {'X-Csrf-Token': csrfToken}, failOnStatusCode: false});
		const contributor = (givenName, whatsapp) => ({
			givenName: {en: givenName},
			familyName: {en: 'Cypress'},
			email: givenName.toLowerCase() + '.' + Date.now() + '@example.invalid',
			userGroupId: Number(authorUserGroupId),
			includeInBrowse: true,
			whatsapp,
		});

		before(function() {
			if (!submissionId || !publicationId || !authorUserGroupId) {
				this.skip();
			}
		});

		beforeEach(function() {
			login();
			cy.visit(url('submissions'));
			cy.window().then((win) => { csrfToken = win.pkp.currentUser.csrfToken; });
		});

		it('Stores an E.164 number and explains the format when it is not', function() {
			request('POST', '', contributor('Invalid', '11 99999-9999')).then((invalid) => {
				expect(invalid.status).to.eq(400);
				expect(invalid.body.whatsapp[0]).to.contain('+5511999999999');
			});

			request('POST', '', contributor('Valid', '+5511999999999')).then((valid) => {
				expect(valid.status, JSON.stringify(valid.body)).to.eq(200);
				added.push(valid.body.id);
				request('GET', '/' + valid.body.id).then((stored) => {
					expect(stored.body.whatsapp).to.eq('+5511999999999');
				});
			});
		});

		after(function() {
			if (added.length) {
				login();
				cy.visit(url('submissions'));
				cy.window().then((win) => {
					csrfToken = win.pkp.currentUser.csrfToken;
					added.forEach((id) => request('DELETE', '/' + id));
				});
			}
		});
	});
});
