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
	// Submissions created by the registration tests, deleted in after() even when an assertion fails.
	const submissions = [];

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

	// A site with the Altcha captcha turned on for registration expects a solved
	// proof of work along with the form. The PKP test data has it off, so this is
	// a no-op there; solving it is what lets the very same spec run against a real
	// installation, which is where the plugin has to work anyway.
	const solveAltcha = (win) => {
		const widget = win.document.querySelector('altcha-widget');
		if (!widget) {
			return;
		}
		const challenge = JSON.parse(widget.getAttribute('challengejson'));
		const encoder = new win.TextEncoder();
		const digest = async (number) => {
			const buffer = await win.crypto.subtle.digest(challenge.algorithm, encoder.encode(challenge.salt + number));
			return [...new Uint8Array(buffer)].map((byte) => byte.toString(16).padStart(2, '0')).join('');
		};

		return (async () => {
			for (let number = 0; number <= (challenge.maxnumber || 100000); number++) {
				if (await digest(number) === challenge.challenge) {
					const input = win.document.createElement('input');
					input.type = 'hidden';
					input.name = 'altcha';
					input.value = win.btoa(JSON.stringify({
						algorithm: challenge.algorithm,
						challenge: challenge.challenge,
						number: number,
						salt: challenge.salt,
						signature: challenge.signature,
						took: 1,
					}));
					win.document.querySelector('form[id=register]').appendChild(input);
					// The floating widget hooks the submit event and would replace
					// what was just put there.
					widget.remove();

					return;
				}
			}
			throw new Error('the Altcha challenge could not be solved');
		})();
	};

	// Fills the registration form with the given number and sends it. Anything a
	// journal may also demand of a new account (an ORCID iD, for instance) is
	// filled when the page asks for it, so the test reports on the number only.
	const registerWith = (number, account) => {
		registrationForm();
		cy.get('form#register input[name="givenName"]').type('Teste', {delay: 0});
		cy.get('form#register input[name="familyName"]').type('WhatsApp', {delay: 0});
		cy.get('form#register input[name="affiliation"]').type('OJSBR', {delay: 0});
		cy.get('form#register select[name="country"]').select('BR');
		cy.get('form#register input[name="email"]').type(account.email, {delay: 0});
		cy.get('form#register input[name="username"]').type(account.username, {delay: 0});
		cy.get('form#register input[name="password"]').type(account.password, {delay: 0, log: false});
		cy.get('form#register input[name="password2"]').type(account.password, {delay: 0, log: false});
		cy.get('body').then(($body) => {
			if ($body.find('form#register input[name="orcid"]').length) {
				cy.get('form#register input[name="orcid"]').type(anOrcid(), {delay: 0});
			}
			if ($body.find('form#register input[name="privacyConsent"]').length) {
				cy.get('form#register input[name="privacyConsent"]').check({force: true});
			}
		});
		cy.get('form#register input[name="whatsapp"]').clear().type(number, {delay: 0});
		// The browser refuses to send a field that does not match its pattern, which
		// is exactly what a person would meet; the server side is what is under test
		// here, so the attribute is dropped and the form is sent as typed.
		cy.get('form#register input[name="whatsapp"]').then(($field) => $field.removeAttr('pattern'));
		cy.window().then((win) => solveAltcha(win));
		cy.get('form#register').submit();
	};

	// The account as the API shows it to an editor, phone included.
	const findAccount = (username) => api(pageUrl('api/v1/users?searchPhrase=' + username + '&count=10'))
		.then((users) => users.items.find((item) => item.userName === username || item.username === username));

	// Enables an account that the journal left disabled awaiting its e-mail
	// validation. Signs in as the editor and uses the action of the users grid.
	const enableAccount = (username) => {
		login(adminUser, adminPassword);
		findAccount(username).then((user) => {
			expect(user, 'the account was created').to.exist;
			cy.window({log: false}).then((win) => request({
				method: 'POST',
				url: pageUrl('$$$call$$$/grid/settings/user/user-grid/disable-user'),
				form: true,
				failOnStatusCode: false,
				body: {userId: user.id, enable: 1, disableReason: '', csrfToken: win.pkp.currentUser.csrfToken},
			}));
		});
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
		// The field is built from the markup of a field the page already had, so
		// it is looked for by what it is, not by the classes of any one theme.
		cy.get('form#register input[type="tel"][name="whatsapp"]').should('have.attr', 'required');
		cy.get('form#register input[name="whatsapp"]').should('have.attr', 'placeholder').and('match', /\+/);
		cy.get('form#register #whatsappDescription').invoke('text').should('match', /\S/).and('not.contain', '##');

		// And it stands with the personal data: after the affiliation and well
		// before the account fields.
		cy.get('form#register input[name="affiliation"], form#register input[name="givenName"]').then(($model) => {
			cy.get('form#register input[name="whatsapp"]').then(($field) => {
				const order = (a, b) => a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING;
				expect(order($model.last()[0], $field[0]), 'the field comes after the personal data').to.be.ok;
			});
			cy.get('form#register input[name="username"], form#register input[type="password"]').then(($account) => {
				cy.get('form#register input[name="whatsapp"]').then(($field) => {
					const order = (a, b) => a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING;
					expect(order($field[0], $account.first()[0]), 'and before the account fields').to.be.ok;
				});
			});
		});
	});

	it('Makes the registration number optional when it is not required', function() {
		configure(false, true);
		registrationForm();
		cy.get('form#register input[name="whatsapp"]').should('exist').and('not.have.attr', 'required');
	});

	// The point of asking for the number on the registration form: it has to end
	// up on the account. A field that is shown and then thrown away is worse than
	// no field at all, so this test registers a person the way a person does and
	// then reads the number back from the account itself.
	it('Saves the number typed on the registration form as the phone of the new account', function() {
		configure(false, true);
		const account = {
			username: 'whatsapp' + Date.now().toString().slice(-8),
			password: 'Ojsbr!Teste2026',
		};
		account.email = account.username + '@mailinator.com';

		registerWith('+55 11 98888-7777', account);
		cy.get('form#register', {timeout: 30000}).should('not.exist');

		// Read back from the account itself, not from the page that was just sent.
		login(adminUser, adminPassword);
		findAccount(account.username).then((user) => {
			expect(user, 'the account was created').to.exist;

			// Read where an editor reads it: the account as the users grid opens it.
			return request({
				url: pageUrl('$$$call$$$/grid/settings/user/user-grid/edit-user') + '?rowId=' + user.id,
				failOnStatusCode: false,
			});
		}).then((response) => {
			// The grid answers with the form inside a JSON envelope.
			const answer = typeof response.body === 'string' ? JSON.parse(response.body) : response.body;
			const form = String(answer.content).replace(/\s+/g, ' ');
			expect(form, 'the account form was opened').to.contain('userDetailsForm');
			expect(form, 'the number typed became the phone of the account')
				.to.match(/name="phone" value="\+5511988887777"/);
		});
	});

	// And a number that is not a number has to say so, on the page, instead of
	// being dropped without a word.
	it('Refuses a number without a country code instead of dropping it', function() {
		configure(false, true);
		const account = {
			username: 'whatsbad' + Date.now().toString().slice(-8),
			password: 'Ojsbr!Teste2026',
		};
		account.email = account.username + '@mailinator.com';

		registerWith('11988887777', account);
		// Still on the form, with the reason shown.
		cy.get('form#register', {timeout: 30000}).should('exist');
		cy.get('form#register').invoke('text').should('match', /E\.164/);
		// And no account was created: signing in with it fails.
		cy.clearCookies();
		request({url: pageUrl('login'), log: false}).then((page) => {
			const token = /name="csrfToken" value="([^"]+)"/.exec(page.body)[1];
			const action = /<form[^>]*id="login"[^>]*action="([^"]+)"/.exec(page.body)[1];
			request({
				method: 'POST',
				url: action,
				form: true,
				failOnStatusCode: false,
				body: {csrfToken: token, username: account.username, password: account.password},
			}).then((response) => {
				expect(response.body, 'no account was created').to.match(/form[^>]*id="login"/);
			});
		});
	});

	// The number of the person who submits is carried to their authorship, the
	// way the core carries the ORCID iD: a new submission starts with an author
	// made from the user, and that author has to have the number.
	it('Carries the phone of the submitter to the contributor of a new submission', function() {
		configure(false, true);
		const account = {
			username: 'whatsaut' + Date.now().toString().slice(-8),
			password: 'Ojsbr!Teste2026',
		};
		account.email = account.username + '@mailinator.com';

		registerWith('+55 11 97777-6666', account);
		cy.get('form#register', {timeout: 30000}).should('not.exist');

		// The person who has just registered starts a submission. The journal
		// gives the author role to whoever submits without one, which is what
		// the submission wizard relies on too.
		// A journal that validates new accounts by e-mail leaves them disabled;
		// the account is enabled the way an editor enables one. The section is
		// read while the editor is still signed in: a brand new account may not
		// read the sections of the journal.
		const journal = {};
		enableAccount(account.username);
		api(pageUrl('api/v1/sections?count=1')).then((sections) => {
			journal.sectionId = sections.items[0].id;
		});

		// A page this account may open in any case, for the session and the token:
		// it has no role in the journal until it submits.
		login(account.username, account.password);
		cy.visit(pageUrl('user/profile') + '?reload=' + Date.now());
		cy.get('#profileTabs', {timeout: 30000}).should('exist');
		cy.window({log: false}).then((win) => {
			const locale = win.pkp.context && win.pkp.context.primaryLocale ? win.pkp.context.primaryLocale : 'en';

			return win.fetch(pageUrl('api/v1/submissions'), {
				method: 'POST',
				credentials: 'same-origin',
				headers: {'Content-Type': 'application/json', 'X-Csrf-Token': win.pkp.currentUser.csrfToken},
				body: JSON.stringify({locale: locale, sectionId: journal.sectionId}),
			}).then((response) => response.json().then((body) => ({status: response.status, body: body})));
		}).then((answer) => {
			expect(answer.status, 'the submission was created: ' + JSON.stringify(answer.body)).to.be.within(200, 201);
			submissions.push(answer.body.id);
			const base = pageUrl('api/v1/submissions/' + answer.body.id + '/publications/' + answer.body.currentPublicationId);

			return api(base + '/contributors');
		}).then((contributors) => {
			const author = contributors.items[0];
			expect(author, 'the submitter became a contributor').to.exist;
			expect(author.whatsapp, 'the phone of the submitter came along').to.eq('+5511977776666');
		});
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
		if (!created.length && !submissions.length) {
			return;
		}
		login(adminUser, adminPassword);
		created.forEach(({base, id}) => withToken('DELETE').then((options) => api(base + '/contributors/' + id, options)));
		submissions.forEach((id) => withToken('DELETE').then((options) => api(pageUrl('api/v1/submissions/' + id), options)));
	});

	it('Puts the settings back', function() {
		if (!original) {
			return;
		}
		configure(original.required, original.registration);
	});
});
