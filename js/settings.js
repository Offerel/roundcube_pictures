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
		hint_td.innerHTML = hint.replace('%link%', "<a href='" + url + "' target='_blank' id='aplink' class='disabled'>Applications</a>");

		let auth_link = document.createElement('a');
		auth_link.id = 'pf_auth_link';
		auth_link.innerText = 'Register App';
		auth_link.href = '#';
		auth_link.addEventListener('click', e => {
			e.preventDefault();
			e.stopPropagation();
			let redirect_url = location.protocol + '//' + location.host + location.pathname + '?_task=pictures';
			let scope = 'read write';

			let regData = new FormData();
			regData.append('client_name', 'Roundcube Photos');
			regData.append('redirect_uris', redirect_url);
			regData.append('scopes', scope);
			regData.append('website', 'https://codeberg.org/Offerel/Roundcube_Pictures');
			let result = sendRequest(base_url, regData, '/api/v1/apps');			
			console.log(result);

			if(result.status === 200) {
				let client_id = result.response.client_id;
				let client_secret = result.response.client_secret;

				let authData = new FormData();
				authData.append('client_id', client_id);
				authData.append('client_secret', client_secret);
				authData.append('redirect_uri', redirect_url);
				authData.append('grant_type', 'client_credentials');
				authData.append('scope', scope);
				result = sendRequest(base_url, authData, '/oauth/token');
				console.log(result);

				if(result.status === 200) {
					let token = result.response.access_token;
					result = sendRequest(base_url, null, '/api/v1/apps/verify_credentials', 'GET', token);
					console.log(result);

					if(result.status === 200) {
						const params = new URLSearchParams({
							client_id: client_id,
							scope: scope,
							redirect_uri: redirect_url,
							response_type: 'code'
						});
						//url = base_url + '/oauth/authorize?' + params.toString();
						window.open(base_url + '/oauth/authorize?' + params.toString(), '_blank').focus();
						// Save to db
					} else {
						rcmail.display_message(rcmail.gettext('app_verify_failed','pictures'), 'error');
					}
				} else {
					rcmail.display_message(rcmail.gettext('app_token_failed','pictures'), 'error');
				}
			} else {
				rcmail.display_message(rcmail.gettext('app_reg_failed','pictures').replace('%instance%', base_url), 'error');
			}
		});

		hint_td.appendChild(auth_link);
		hint_tr.appendChild(hint_td);
		token.parentNode.insertBefore(hint_tr, token);

		if(instance.value.length != 0) document.getElementById('aplink').classList.remove('disabled');
	}
});

function sendRequest(url, data, endpoint, method = 'POST', token) {
	xhr = new XMLHttpRequest();
	xhr.onload = function() {
		let result = {};
		if(xhr.status === 200) result.response = this.response;
		result.status = xhr.status;
		return result;
	}

	xhr.open(method, url + endpoint);
	if(token !== undefined) xhr.setRequestHeader('Authorization', 'Bearer ' + token);
	xhr.responseType = 'json';
	xhr.send(data);
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