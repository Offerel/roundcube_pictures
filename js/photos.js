window.onload = function(){
	$('.dgal').justifiedGallery({
		rowHeight: 190,
		margins: 5,
		border: 0,
		rel: 'gallery',
		lastRow: 'nojustify',
		captions: false,
		randomize: false,
	});

	/*
	$('body').justifiedGallery().on('jg.complete', function(e) {
		if(e.currentTarget.clientHeight > 100 && e.currentTarget.clientHeight < document.documentElement.clientWidth) {
			lazyload();
		}
	});
	*/

	for (let e of document.querySelectorAll('.marker, .dhfmt')) {
		e.addEventListener('click', d => {
			let cb = e.parentNode.dataset.day;
			
			if (e.dataset.cb == 1) {
				for (let c of document.querySelectorAll('[data-dday=\"'+cb+'\"]')) {
					c.checked = false;
				}
				e.dataset.cb = 0;
			} else {
				for (let c of document.querySelectorAll('[data-dday=\"'+cb+'\"]')) {
					c.checked = true;
				}
				e.dataset.cb = 1;
			}
			count_checks();
		});
	}

	for (let e of document.querySelectorAll('.icheckbox')) {
		e.addEventListener('change', count_checks);
	}
}

function count_checks() {
	let marked = document.querySelectorAll('input[type=\"checkbox\"]:checked').length;
	let scount = document.getElementById('scount');

	if(marked > 0) {
		scount.innerText = scount.dataset.text.replace('%count%', marked);
		scount.style.display = 'inline';
		window.parent.document.getElementById('movepicture').classList.remove('disabled');
		window.parent.document.getElementById('delpicture').classList.remove('disabled');
		window.parent.document.getElementById('sharepicture').classList.remove('disabled');
		window.parent.document.getElementById('editmeta').classList.remove('disabled');
	} else {
		scount.innerText = '';
		scount.style.display = 'none';
		window.parent.document.getElementById('movepicture').classList.add('disabled');
		window.parent.document.getElementById('delpicture').classList.add('disabled');
		window.parent.document.getElementById('sharepicture').classList.add('disabled');
		window.parent.document.getElementById('editmeta').classList.add('disabled');
	}
}