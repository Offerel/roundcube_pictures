/**
 * Roundcube Pictures Plugin
 *
 * @version 1.5.6
 * @author Offerel
 * @copyright Copyright (c) 2025, Offerel
 * @license GNU General Public License, version 3
 */
window.rcmail && rcmail.addEventListener("init", function(a) {
	if(document.getElementById('pixelfed_instance')) {
		let instance = document.getElementById('pixelfed_instance');
		instance.addEventListener('change', checkInstance);
		document.getElementById('pixelfed_token').addEventListener('change', checkToken);
		
		let token = document.getElementById('pixelfed_token').parentElement.parentElement;
		let hint_tr = document.createElement('tr');
		let hint_td = document.createElement('td');
		hint_td.colSpan = 2;
		hint_td.classList.add('tdhint');
		let hint = rcmail.gettext('pfapphint','pictures');
		let url = instance.value;
		let base_url = (url.endsWith('/')) ? url.substr(0, url.length - 1):url;
		url = base_url + '/settings/applications';

		let auth_link = document.createElement('a');
		auth_link.id = 'pf_auth_link';
		auth_link.innerText = 'Register App';
		auth_link.href = '#';
		auth_link.addEventListener('click', registerApp);

		hint_td.appendChild(auth_link);
		hint_tr.appendChild(hint_td);
		token.parentNode.insertBefore(hint_tr, token);
	}
});

function registerApp(e) {
	e.preventDefault();
	e.stopPropagation();
	let instance = document.getElementById('pixelfed_instance');
	let url = instance.value;
	let token = '';
	let client_id = 'fsfsfdokf';
	let client_secret = 'sdfsdfffffewfds';
	let scope = 'read write';
	let redirect_uri = location.protocol + '//' + location.host + location.pathname + '?_task=pictures';
	instance.value = (url.endsWith('/')) ? url.substr(0, url.length - 1):url;

	let cApp = createApp(redirect_uri, scope, instance.value);
	if(cApp.status === 200) {
		client_id = cApp.response.client_id;
		client_secret = cApp.response.client_secret;
	} else {
		rcmail.display_message(rcmail.gettext('app_reg_failed','pictures').replace('%instance%', instance.value), 'error');
		return false;
	}

	let gToken = getToken(client_id, client_secret, redirect_uri, scope, instance.value);
	if(gToken.status === 200) {
		token = gToken.response.access_token;
	} else {
		rcmail.display_message(rcmail.gettext('app_token_failed','pictures'), 'error');
		return false;
	}

	let vApp = verifyApp(token, instance.value);
	if(vApp.status !== 200) {
		rcmail.display_message(rcmail.gettext('app_verify_failed','pictures'), 'error');
		return false;
	}
	

	getCode();

	function createApp(redirect, scopes, instance) {
		let regData = new FormData();
		regData.append('client_name', 'Roundcube Photos');
		regData.append('redirect_uris', redirect);
		regData.append('scopes', scopes);
		regData.append('website', 'https://codeberg.org/Offerel/Roundcube_Pictures');
		let response = sendRequest(instance, regData, '/api/v1/apps');
		return response;
	}

	function getToken(client, secret, redirect, scope, instance) {
		let authData = new FormData();
		authData.append('client_id', client);
		authData.append('client_secret', secret);
		authData.append('redirect_uri', redirect);
		authData.append('grant_type', 'client_credentials');
		authData.append('scope', scope);
		result = sendRequest(instance, authData, '/oauth/token');
		return result;
	}

	function verifyApp(token, instance) {
		result = sendRequest(instance, null, '/api/v1/apps/verify_credentials', 'GET', token);
		return result;
	}

	function getCode() {
		const params = new URLSearchParams({
			client_id: client_id,
			scope: scope,
			redirect_uri: redirect_uri,
			response_type: 'code'
		});

		const cookie_data = JSON.stringify({
			client_id:client_id,
			client_secret:client_secret,
			redirect_uri:redirect_uri,
			instance:instance.value,
			token:token,
			scope:scope
		});

		document.cookie = "appval=" + (cookie_data || "") + "; max-age=1800; secure; path=/";

		let wind = window.open(instance.value + '/oauth/authorize?' + params.toString(), '_top', 'width=300,height=200,menubar=no,status=no');
	}
}

function sendRequest(url, data, endpoint, method = 'POST', token) {
	let result = {};
	let xhr = new XMLHttpRequest();
	xhr.onload = function() {
		result.status = xhr.status;
		if(xhr.status === 200) {
			result.response = JSON.parse(this.response);
		}
	}

	xhr.open(method, url + endpoint, false);
	if(token !== undefined) xhr.setRequestHeader('Authorization', 'Bearer ' + token);
	xhr.send(data);
	return result;
}

function checkToken() {
	let token = document.getElementById('pixelfed_token');
	let instance = document.getElementById('pixelfed_instance');
	let url = instance.value.endsWith('/') ? instance.value.substr(0, instance.value.length - 1):instance.value;

	xhr = new XMLHttpRequest();
	xhr.onreadystatechange = function() {
		if (this.readyState == 4 && this.status == 200) {
			let data = JSON.parse(this.responseText);
			if(data.acct !== undefined) {
				rcmail.display_message('Authentication for ' + data.acct + ' successful', 'confirmation');
				token.classList.add('success');
				token.classList.remove('error');
			} else {
				rcmail.display_message('Authentication failed', 'error');
				token.classList.remove('success');
				token.classList.add('error');
			}
		} else {
			rcmail.display_message('Pixelfed Server error, please check instance', 'error');
			token.classList.remove('success');
			token.classList.add('error');
		}
	}

	xhr.open('GET', url + '/api/v1/accounts/verify_credentials');
	xhr.setRequestHeader('Authorization', 'Bearer ' + token.value);
	xhr.send();
}

function checkInstance() {
	let instance = document.getElementById('pixelfed_instance');
	let aplink = document.getElementById('aplink');
	let url = instance.value.endsWith('/') ? instance.value.substr(0, instance.value.length - 1):instance.value;

	xhr = new XMLHttpRequest();
	xhr.onreadystatechange = function() {
		if (this.readyState == 4 && this.status == 200) {
			let data = JSON.parse(this.responseText);
			if(data.version !== undefined) {
				let version = '';
				switch(data.title) {
					case 'Pixelfed':
						version = data.version.split('; ')[1];
						break;
					case 'Mastodon':
						version = data.version;
						break;
					default:
						return false;
				}
				
				rcmail.display_message(data.title + ' instance with ' + version + ' found', 'confirmation');

				aplink.classList.remove('disabled');
				instance.classList.add('success');
				instance.classList.remove('error');
				aplink.href = url + '/settings/applications';
			} else {
				rcmail.display_message('Invalid Pixelfed URL, version check failed', 'error');
				instance.classList.add('error');
				instance.classList.remove('success');
				aplink.classList.add('disabled');
			}
		}
	}
	xhr.open('GET', url + '/api/v2/instance');
	xhr.send();
}