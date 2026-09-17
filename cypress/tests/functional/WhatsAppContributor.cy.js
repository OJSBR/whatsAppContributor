/**
 * @file cypress/tests/functional/WhatsAppContributor.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests: the settings, the registration field, and the number of a
 * contributor saved through the REST endpoints the contributor form uses.
 *
 * Parameters (--env): contextPath, adminUser, adminPassword (captcha on login
 * must be off for the run). The defaults match the data set of PKP's continuous
 * integration; the first test enables the plugin when it is off. The contributor
 * test uses the first submission in progress of the journal. Settings touched
 * are put back and contributors added are deleted. Assertions use names, ids
 * and API data, never labels.
 */

describe('WhatsApp Contributor plugin', function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';

	const rowName = 'whatsappcontributorplugin';
	const settingsForm = 'form[id="whatsAppContributorSettings"]';
	let original = null;
	// Contributors created by the contributor test, deleted in after() even when an assertion fails.
	const created = [];

	// A valid iD, different at each call (two contributors of a publication may not share one).
	let orcidSeed = Math.floor(Math.random() * 900000);
	const anOrcid = () => {
		const digits = ('000000021' + String(orcidSeed++).padStart(6, '0')).slice(0, 15);
		let total = 0;
		for (const digit of digits) {
			total = (total + Number(digit)) * 2;
		}
		const result = (12 - (total % 11)) % 11;

		return 'https://orcid.org/' + (digits + (result === 10 ? 'X' : String(result))).replace(/(.{4})(.{4})(.{4})(.{4})/, '$1-$2-$3-$4');
	};

	// ---- OJSBR spec helpers (padrão v2): work on OJS/OMP 3.3, 3.4 and 3.5 and in PKP's CI ----

	const pageUrl = (path) => '/index.php/' + contextPath + (path ? '/' + path : '');

	// Same as PKP's cy.waitJQuery(), which the support files of OJS 3.3 test sites may lack.
	// The Plugins tab can keep requests open for a while (the plugin gallery), hence the timeout.
	const waitJQuery = () => cy.window().its('jQuery.active', {timeout: 60000}).should('eq', 0);

	// Requests carry the browser's User-Agent: OJS 3.3 drops a session whose agent changes.
	const request = (options) => cy.window({log: false}).then((win) => cy.request(Object.assign(
		typeof options === 'string' ? {url: options} : options,
		{headers: Object.assign({'User-Agent': win.navigator.userAgent}, (typeof options === 'string' ? {} : options.headers) || {})}
	)));

	// Signs in through requests (the login page can re-render while it is typed into), then
	// falls back to the form when the session did not stick (OJS 3.3 cookie handling).
	const login = (username, password) => {
		cy.clearCookies();
		request(pageUrl('login')).then((response) => {
			const token = /name="csrfToken" value="([^"]+)"/.exec(response.body)[1];
			// The form posts to the URL with the language: a redirect would turn the POST into a GET.
			const action = /<form[^>]*id="login"[^>]*action="([^"]+)"/.exec(response.body)[1];
			request({method: 'POST', url: action, form: true, body: {csrfToken: token, username: username, password: password}, log: false});
		});
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('body').then(($body) => {
			if ($body.find('form#login').length) {
				cy.get('form#login input[name="username"]').type(username, {delay: 0});
				cy.get('form#login input[name="password"]').type(password, {delay: 0, log: false});
				cy.get('form#login').submit();
				cy.get('form#login', {timeout: 30000}).should('not.exist');
			}
		});
	};

	// REST API calls made from the page itself, so they carry the browser's own session.
	const api = (path, options = {}) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, Object.assign({credentials: 'same-origin'}, options)).then((response) => {
			if (!response.ok) {
				return response.text().then((text) => {
					throw new Error(path + ' answered ' + response.status + ': ' + text.slice(0, 300));
				});
			}
			return response.json();
		}),
		{log: false, timeout: 30000}
	));

	// The website settings page on its Plugins tab (a new query string forces a load). Load it
	// once per test: loading it again while its plugin gallery request is pending stalls the
	// web server of PKP's CI; API calls and settings modals work on the page already open.
	const openPluginsTab = () => {
		cy.visit(pageUrl('management/settings/website') + '?reload=' + Date.now() + '#plugins');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).click();
		cy.get('button[id="plugins-button"]').should('have.attr', 'aria-selected', 'true');
		waitJQuery();
	};

	// Enables the plugin in the grid when it is off (never turns it off).
	const enablePlugin = (rowName) => {
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]', {timeout: 30000}).then(($checkbox) => {
			if (!$checkbox.is(':checked')) {
				cy.wrap($checkbox).click();
				waitJQuery();
			}
		});
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]').should('be.checked');
	};

	// Opens the settings modal from the grid, without reloading the page: a reload right
	// after saving can stall the web server of PKP's CI. The form is fetched each time.
	const openPluginSettings = (rowName, formSelector) => {
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]', {timeout: 30000}).then(($link) => {
			if (!$link.is(':visible')) {
				cy.get('tr[id$="-row-' + rowName + '"] a.show_extras').first().click();
			}
		});
		// The grid may still be animating the extras row: the link is clicked once it exists.
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]').first().click({force: true});
		waitJQuery();
		cy.window().should((win) => {
			expect(win.jQuery(formSelector).data('pkp.handler')).to.exist;
		});
	};

	// ---- end of helpers ----

	const openSettings = () => openPluginSettings(rowName, settingsForm);

	// Loads the website settings page once and saves the two settings.
	const configure = (required, registration) => {
		login(adminUser, adminPassword);
		openPluginsTab();
		openSettings();
		cy.get(settingsForm + ' input[name="whatsappRequired"]')[required ? 'check' : 'uncheck']({force: true});
		cy.get(settingsForm + ' input[name="showOnRegistration"]')[registration ? 'check' : 'uncheck']({force: true});
		cy.get(settingsForm + ' button[id^="submitFormButton-"]').click({force: true});
		waitJQuery();
		cy.get(settingsForm).should('not.exist');
	};

	const registrationForm = () => {
		cy.clearCookies();
		cy.visit(pageUrl('user/register') + '?reload=' + Date.now());
		cy.get('form#register', {timeout: 30000}).should('exist');
	};

	const withToken = (method, body) => cy.window({log: false}).then((win) => ({
		method,
		headers: {'Content-Type': 'application/json', 'X-Csrf-Token': win.pkp.currentUser.csrfToken},
		body: body ? JSON.stringify(body) : undefined,
	}));

	it('Enables the plugin', function() {
		login(adminUser, adminPassword);
		openPluginsTab();
		enablePlugin(rowName);
		openSettings();
		cy.get(settingsForm + ' input[name="whatsappRequired"]').then(($required) => {
			cy.get(settingsForm + ' input[name="showOnRegistration"]').then(($registration) => {
				original = {required: $required.is(':checked'), registration: $registration.is(':checked')};
			});
		});
	});

	it('Leaves the registration form alone when it is not configured', function() {
		configure(false, false);
		registrationForm();
		cy.get('form#register input[name="whatsapp"]').should('not.exist');
	});

	it('Asks for the number on the registration form, required when required', function() {
		configure(true, true);
		registrationForm();
		cy.get('form#register fieldset.identity input[type="tel"][name="whatsapp"]').should('have.attr', 'required');
		cy.get('form#register #whatsAppContributorDescription').invoke('text').should('match', /\S/).and('not.contain', '##');
	});

	it('Makes the registration number optional when it is not required', function() {
		configure(false, true);
		registrationForm();
		cy.get('form#register input[name="whatsapp"]').should('exist').and('not.have.attr', 'required');
	});

	it('Stores an E.164 number for a contributor and explains the format when it is not', function() {
		login(adminUser, adminPassword);
		api(pageUrl('api/v1/submissions?status=1&count=20')).then((submissions) => {
			const submission = submissions.items.find((item) => item.currentPublicationId);
			expect(submission, 'a submission in progress').to.exist;
			const base = pageUrl('api/v1/submissions/' + submission.id + '/publications/' + submission.currentPublicationId);
			api(base).then((publication) => {
				const userGroupId = publication.authors.length ? publication.authors[0].userGroupId : null;
				expect(userGroupId, 'an author user group').to.exist;
				// Names in the language of the submission, which the schema requires.
				const contributor = (givenName, whatsapp) => ({
					givenName: {[submission.locale]: givenName},
					familyName: {[submission.locale]: 'Cypress'},
					email: givenName.toLowerCase() + '.' + Date.now() + '@example.invalid',
					userGroupId,
					includeInBrowse: true,
					whatsapp,
				});

				const post = (payload) => withToken('POST', payload).then((options) => cy.window({log: false}).then((win) => cy.wrap(
					win.fetch(base + '/contributors', Object.assign({credentials: 'same-origin'}, options)).then((response) => response.json().then((body) => ({status: response.status, body}))),
					{log: false, timeout: 30000}
				)));

				// An invalid number is refused with the expected format in the message.
				post(contributor('Invalid', '11 99999-9999')).then((invalid) => {
					if (invalid.body && invalid.body.id) {
						created.push({base, id: invalid.body.id});
					}
					expect(invalid.status).to.eq(400);
					expect(invalid.body.whatsapp[0]).to.contain('+5511999999999');
				});

				// Other plugins of the journal may hold a contributor for an iD, an affiliation or a
				// biography. Those are only sent when the refusal asks for them: a typed iD is refused
				// where OJSBR's orcidManualEntry is not installed, and the affiliation has a
				// different shape in 3.4 and 3.5.
				const valid = contributor('Valid', '+5511999999999');
				post(valid).then((first) => {
					if (first.status !== 400) {
						return cy.wrap(first, {log: false});
					}
					expect(first.body, 'refused for something else than the number: ' + JSON.stringify(first.body)).to.not.have.property('whatsapp');
					const completed = Object.assign({}, valid);
					if (first.body.orcid) {
						completed.orcid = anOrcid();
					}
					if (first.body.affiliations) {
						completed.affiliations = [{name: {[submission.locale]: 'Universidade Federal do Cypress'}}];
					}
					if (first.body.affiliation) {
						completed.affiliation = {[submission.locale]: 'Universidade Federal do Cypress'};
					}
					if (first.body.biography) {
						completed.biography = {[submission.locale]: '<p>Cypress.</p>'};
					}
					return post(completed);
				}).then((saved) => {
					if (saved.body && saved.body.id) {
						created.push({base, id: saved.body.id});
					}
					expect(saved.status, JSON.stringify(saved.body)).to.eq(200);
					api(base + '/contributors/' + saved.body.id).then((stored) => expect(stored.whatsapp).to.eq('+5511999999999'));
				});
			});
		});
	});

	after(function() {
		if (created.length) {
			login(adminUser, adminPassword);
			created.forEach(({base, id}) => withToken('DELETE').then((options) => api(base + '/contributors/' + id, options)));
		}
	});

	it('Puts the settings back', function() {
		if (!original) {
			return;
		}
		configure(original.required, original.registration);
	});
});
