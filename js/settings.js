/**
 * Roundcube Pictures Plugin
 *
 * @version 1.5.3
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
		url = (url.endsWith('/')) ? url.substr(0, url.length - 1):url;
		url = url + '/settings/applications';
		hint_td.innerHTML = hint.replace('%link%', "<a href='" + url + "' target='_blank' id='aplink' class='disabled'>Applications</a>");
		hint_tr.appendChild(hint_td);
		token.parentNode.insertBefore(hint_tr, token);

		if(instance.value.length != 0) document.getElementById('aplink').classList.remove('disabled');
	}
});

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
				let version = data.version.split('; ')[1];
				version = version.substr(0, version.length - 1).split(' ')[1];
				rcmail.display_message('Pixelfed instance with ' + version + ' found', 'confirmation');
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