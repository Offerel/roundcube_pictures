/**
 * Roundcube Photos Plugin
 *
 * @version 1.5.9
 * @author Offerel
 * @copyright Copyright (c) 2025, Offerel
 * @license GNU General Public License, version 3
 */
var intervalID;
window.onload = function(){
	let scount = document.getElementById('scount');
	let prd = scount.dataset.prd;
	let btitle = scount.dataset.title.split('/');
	let ttitle = (btitle.length > 0 && btitle[0] != '') ? prd + ' - ' + btitle[btitle.length - 1 ]:prd;
	window.parent.document.title = ttitle;

	if (document.readyState !== 'complete') {
		aLoader('hidden');
	}

	initGallery();

	$('.fimages').justifiedGallery().on('jg.complete', function(e) {
		if(e.currentTarget.clientHeight > 100 && e.currentTarget.clientHeight < document.documentElement.clientWidth && e.currentTarget.classList.contains('alb')) {
			lazyload();
		}
		
		for(e of document.getElementsByClassName('icheckbox')) {
			e.addEventListener('change', count_checks);
		}
	});

	$(window).scroll(function() {
		lazyload();
	});

	lightbox = GLightbox({
		plyr: {
			config: {
				muted: true,
			}
		},
		autoplayVideos: false,
		loop: false,
		videosWidth: '100%',
		closeOnOutsideClick: false,
		closeButton: true
	});

	lightbox.on('slide_changed', (data) => {
		let cindex = data.current.index + 1;
		let cimages = lightbox.elements.length;
		let loop_play = (document.getElementById('pbtn')) ? document.getElementById('pbtn').classList.contains('on'):false;
		let file = new URL(data.current.slideConfig.href).searchParams.get('file').split('/').slice(-1)[0];
		let dlbtn = document.createElement('button');
		let fbtn = document.createElement('button');
		dlbtn.id = 'dlbtn';
		fbtn.id = 'fbtn';
		dlbtn.addEventListener('click', e => {
			window.location = 'simg.php?w=3&file=' + new URL(data.current.slideConfig.href).searchParams.get('file').replace(/([^:])(\/\/+)/g, '$1/');
		})
		fbtn.addEventListener('click', e => {
			if(document.fullscreenElement){
				document.exitFullscreen() 
			} else { 
				document.getElementById('glightbox-body').requestFullscreen();
			}
			fbtn.classList.toggle('on');
		});
		let closebtn = document.querySelector('.gclose');
		
		if(document.getElementById('btn_container')) document.getElementById('btn_container').remove();
		let btn_container = document.createElement('div');
		btn_container.id = 'btn_container';

		let iBox = document.getElementById(file);
		let infobtn = document.createElement('button');
		infobtn.id = 'infbtn';
		
		if(document.getElementById(file)) {
			infobtn.dataset.iid = file;
			infobtn.addEventListener('click', iBoxShow, true);
		} else {
			infobtn.disabled = true;
		}

		let pbtn = document.createElement('button');
		pbtn.id = 'pbtn';
		if(loop_play) {
			pbtn.classList.add('on');
			pbtn.addEventListener('click', stop_loop);
		} else {
			pbtn.addEventListener('click', loop_slide.bind(this, 5));
		}

		let sbtn = document.createElement('button');
		sbtn.id = 'sbtn';
		sbtn.addEventListener('click', e => {
			let href = 'simg.php' + new URL(data.current.slideConfig.href).search;
			document.querySelector('[href="' + href + '"]').parentElement.querySelector('.icheckbox').checked = true;
			window.parent.selectShare();
		});
		
		btn_container.appendChild(pbtn);
		btn_container.appendChild(dlbtn);
		btn_container.appendChild(sbtn);
		btn_container.appendChild(infobtn);
		btn_container.appendChild(fbtn);
		btn_container.appendChild(closebtn);
		let gcontainer = document.querySelector('.gcontainer');
		gcontainer.appendChild(btn_container);

		if(document.getElementById('infobox')) iBoxShow();

		if(document.getElementById('last') && cindex === cimages) {
			stop_loop();
		}
	});

	lightbox.on('slide_before_change', (data) => {
		let cindex = data.current.index + 1;
		let cimages = lightbox.elements.length;
		let last = document.getElementById('last') ? false:true;
		if(cindex == cimages && last) {
			setTimeout(lazyload, 100, true);
		}
	});

	lightbox.on('close', () => {
		stop_loop();
		document.querySelectorAll('.exinfo').forEach(element => {
			element.classList.remove('eshow');
		});
	});

	lightbox.on('open', () => {
		document.querySelector('.gclose').addEventListener('click', () => {
			if(document.getElementById('infbtn')) document.getElementById('infbtn').remove();
			if(document.getElementById('dlbtn'))document.getElementById('dlbtn').remove();
			if(document.getElementById('fbtn'))document.getElementById('fbtn').remove();
			document.querySelectorAll('.exinfo').forEach(element => {
				element.classList.remove('eshow');
			});
		}, {once: true});
	});

	Array.from(document.getElementsByClassName('folder')).forEach(
		function(e,i,a) { e.addEventListener('click', function() {aLoader()}) }
	);

	checkboxes();
	markDay();

	let prevScrollpos = window.scrollY;
	let header = document.getElementById('header');

	if(header) {
		let headerN = document.querySelector('#header .breadcrumb');
		headerN.lastElementChild.previousElementSibling.addEventListener('click', function(e){
			if(headerN.childElementCount > 2) {
				e.preventDefault();
				window.parent.edit_album();
			}
		});

		document.getElementById('aadd').addEventListener('click', e => {
			parent.add_album();
		});

		window.onscroll = function() {
			var currentScrollPos = window.scrollY;
			if (prevScrollpos > currentScrollPos) {
				header.style.top = '0';
				(currentScrollPos > 150) ? header.classList.add('shadow'):header.classList.remove('shadow');
			} else {
				header.style.top = '-55px';
				header.classList.remove('shadow')
			}
			prevScrollpos = currentScrollPos;
		}
	}

	scount.addEventListener('click', e => {
		parent.rm_checks();
	});

	var dropZones = document.getElementsByClassName('dropzone');
	for (var i = 0; i < dropZones.length; i++) {
		dropZones[i].addEventListener('dragover', handleDragOver, false);
		dropZones[i].addEventListener('dragleave', handleDragLeave, false);
		dropZones[i].addEventListener('drop', handleDrop, false);
	}

	let moveCal = document.getElementById('moveCal');

	const cMove = (e) => {
		try {
			var y = !isTouchDevice() ? e.clientY : e.touches[0].clientY;
			var x = !isTouchDevice() ? e.clientX : e.touches[0].clientX	;	
		} catch (e) {}

		moveCal.style.top = (y > 30) ? y - 2 + "px":"26px";
		
		setTimeout(() => {
			let obj = document.elementFromPoint(x,y);

			if(obj.classList.contains('myd')) {
				moveCal.innerText = obj.dataset.range;
				moveCal.dataset.ts = obj.dataset.ts;
			}
		}, 10);
	};
	const cClick = (e) => {
		let images = document.querySelectorAll('.icheckbox');

		const data = JSON.stringify({
			action: 'timeline',
			data: {
				s:parseInt(images[images.length -1].dataset.os),
				t:document.getElementById('moveCal').dataset.ts
			}
		});
	
		xhr = new XMLHttpRequest();
		xhr.onload = function() {
			aLoader('hidden');
			let response = this.response;
			
			const html = new DOMParser().parseFromString(response, 'text/html');
			let day = html.querySelector('.ddiv').dataset.day;
			let ts = html.querySelector('.ddiv').dataset.ts;

			let ddivs = document.getElementsByClassName('ddiv');
			let eTSarr = new Array();
			for (let index = 0; index < ddivs.length; index++) {
				eTSarr.push(ddivs[index].dataset.ts);
			}

			const tsCheck = num => eTSarr.find(v => v < num);
			let iBefore = tsCheck(ts);

			if(iBefore === undefined) {
				$('#timeline').append(response);
			} else {
				$('[data-ts="'+ iBefore + '"]').before(response);
			}

			html.body.childNodes.forEach(element => {
				if (element instanceof HTMLDivElement) {
					if(element.classList.contains('fimages')) {
						for (let e of element.querySelectorAll('.image')) {
							lightbox.insertSlide({
								'href': e.firstChild.href,
								'type': e.firstChild.dataset.type
							});
						}
					}
				}
			});

			initGallery();
			markDay();

			lightbox.reload();
			
			document.querySelector('[data-day=\''+day+'\']').scrollIntoView({ behavior: "smooth", block: "start", inline: "start" });
			
			return false;
		}
		xhr.open('POST', 'photos.php');
		xhr.setRequestHeader('Content-type', 'application/json; charset=utf-8');
		xhr.send(data);
	};

	if(document.getElementById('scroller')) {
		document.getElementById('scroller').addEventListener("mousemove", cMove);
		document.getElementById('scroller').addEventListener("touchmove", cMove);
		document.getElementById('scroller').addEventListener("click", cClick);
	}
}

function isTouchDevice() {
	try {
		document.createEvent("TouchEvent");
		return true;
	} catch (e) {
		return false;
	}
}

function initGallery() {
	let gmargin = parseInt(document.getElementById('scount').dataset.margin);

	$('#folders').justifiedGallery({
		rowHeight: 220,
		maxRowHeight: 220,
		margins: gmargin,
		border: 0,
		lastRow: 'nojustify',
		captions: false,
		randomize: false,
	});

	$('.fimages').justifiedGallery({
		rowHeight: 190,
		maxRowHeight: 220,
		margins: gmargin,
		border: 0,
		lastRow: 'nojustify',
		captions: true,
		randomize: false,
		
	});
}

function markDay() {
	for (let e of document.querySelectorAll('.marker, .dhfmt')) {
		e.addEventListener('click', d => {
			let cb = e.parentNode.dataset.day;
			
			if (e.dataset.cb == 1) {
				for (let c of document.querySelectorAll('[data-dday=\"'+cb+'\"]')) {
					c.checked = false;
				}
				e.dataset.cb = 0;
				e.parentElement.firstElementChild.classList.remove('marked');
			} else {
				for (let c of document.querySelectorAll('[data-dday=\"'+cb+'\"]')) {
					c.checked = true;
				}
				e.dataset.cb = 1;
				e.parentElement.firstElementChild.classList.add('marked');
			}
			count_checks();
		});
	}
}

function count_checks() {
	let marked = document.querySelectorAll('.icheckbox:checked').length;

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

function checkboxes() {
	let chkboxes = $('.icheckbox');
	let lastChecked = null;
	chkboxes.click(function(e) {
		if(!lastChecked) {
			lastChecked = this;
			return;
		}

		if(e.shiftKey) {
			let start = chkboxes.index(this);
			let end = chkboxes.index(lastChecked);
			chkboxes.slice(Math.min(start,end), Math.max(start,end)+ 1).prop('checked', lastChecked.checked);

		}
		lastChecked = this;
	});
}

function iBoxShow(e) {
	let info = document.getElementById(document.getElementById('infbtn').dataset.iid);
	let infobox = info.cloneNode(true);
	infobox.id = 'infobox';
	infobox.classList.add('eshow');

	if(document.getElementById('infobox')) {
		document.getElementById('infobox').remove();
		if(e == undefined) document.querySelector('.gcontainer').append(infobox);
	} else {
		document.querySelector('.gcontainer').append(infobox);
	}
}

function stop_loop() {
	if(document.getElementById('pbtn')) {
		let pbtn = document.getElementById('pbtn');
		pbtn.classList.remove('on');
		pbtn.addEventListener('click', loop_slide.bind(this, 5));
	}
	
	clearInterval(intervalID);
	document.getElementById('slide_progress').style.width = 0;
}

function loop_slide(duration=3) {
	document.getElementById('pbtn').classList.add('on');

	lightbox.nextSlide();
	var width = 1;
	intervalID = setInterval(frame, 10);
	function frame() {
		if (width >= 100) {
			clearInterval(intervalID);
			loop_slide(duration);
		} else {
			width = width + (100/(duration*60));
			document.getElementById('slide_progress').style.width = width + 'vw';
		}
	}
}

function aLoader(mode = 'visible') {
	document.getElementById('loader').style.visibility = mode;
}	

function handleDragOver(event){
	event.preventDefault();
	if(this.localName != 'body') {
		this.classList.add('mmn-drop');
	}
}

function handleDragLeave(event){
	event.preventDefault();
	this.classList.remove('mmn-drop');
}

function handleDrop(event){
	event.stopPropagation();
	event.preventDefault();
	this.classList.remove('mmn-drop');
	
	let url = (this.parentElement.href) ? this.parentElement.href:location.href;
	let folder = new URL(url).searchParams.get('p');
	startUpload(event.dataTransfer.files, folder);
}

function startUpload(files, folder) {
	let formdata = new FormData();
	xhr = new XMLHttpRequest();
	let cookies = document.cookie.split('; ');
	let maxfiles = 0;
	cookies.forEach(element => {
		let e = element.split('=');
		if(e[0] === 'rcpmf') maxfiles = e[1];
	});
	let mimeTypes = ['image/jpeg', 'video/mp4'];
	folder = decodeURIComponent((folder + '').replace(/\+/g, '%20'));
	let progressBar = document.getElementById('progress');
	if (files.length > maxfiles) {
		console.log('You try to upload more than the max count of allowed files(' + maxfiles + ')');
		return false;
	} else {
		for (var i = 0; i < files.length; i++) {
			if (mimeTypes.indexOf(files.item(i).type) == -1) {
				console.log('Unsupported filetype(' + files.item(i).name + '), exiting');
				return false;
			} else {
				formdata.append('galleryfiles[]', files.item(i), files.item(i).name);
				formdata.append('folder',folder);
			}
		}
		
		xhr.upload.addEventListener('progress', function(event) {
			let percentComplete = Math.ceil(event.loaded / event.total * 100);
			progressBar.style.visibility = 'visible';
			progressBar.firstChild.innerHTML = percentComplete + '%';
			progressBar.firstChild.style.width = percentComplete + '%';
		});

		xhr.onload = function() {
			if (xhr.status === 200) {
				let data = JSON.parse(xhr.responseText);
				let bg = '';

				for (var i = 0; i < data.length; i++) {
					switch(data[i].type) {
						case 'error': bg = '#dc3545'; break;
						case 'warning': bg = '#ffc107'; break;
						default: bg = '#28a745';
					}

					progressBar.firstChild.style.backgroundColor = bg;
					console.log(data[i].message);
				}

				setTimeout(function() {
					progressBar.style.visibility = 'hidden';
					progressBar.firstChild.style.width = 0;
					progressBar.firstChild.style.backgroundColor = '#007bff';
					location.reload();
					count_checks();
				}, 5000);
			}
		}
		
		xhr.open('POST', 'photos.php');
		xhr.send(formdata);
	}
}

function lazyload(slide = false) {
	if(document.getElementById('last') && !slide) return false;
	if(document.getElementsByClassName('glightbox').length <= 0 && !slide) return false;
	
	let wheight = $(document).height() - 10;
	let wposition = Math.ceil($(window).scrollTop() + $(window).height());
	let gal = (document.getElementById('timeline')) ? 'timeline':scount.dataset.title;

	if(wposition > wheight || slide) {
		let tse = document.querySelectorAll(".ddiv");
		let ts = (gal === 'timeline') ? parseInt(tse[tse.length -1].dataset.ts) + 1:$('.glightbox').length;
		let date = new Date(ts * 1000);

		date.setHours(0)
		date.setMinutes(0)
		date.setSeconds(-1)

		$.ajax({
			type: 'POST',
			url: 'photos.php',
			async: false,
			beforeSend: aLoader('visible'),
			data: JSON.stringify({
				action: 'lazyload',
				g: gal,
				s: ts,
				t: Date.parse(date)/1000
			}),
			contentType: 'application/json; charset=utf-8',
			success: function(response) {
				aLoader('hidden');
				
				if(gal == 'timeline') {
					$('#timeline').append(response);
					initGallery();
					markDay();
				} else {
					$('.fimages').append(response);
					$('.fimages').justifiedGallery('norewind');
				}

				const html = new DOMParser().parseFromString(response, 'text/html');
				html.body.childNodes.forEach(element => {
					if (element instanceof HTMLDivElement) {
						if(gal === 'timeline') {
							if(element.classList.contains('fimages')) {
								for (let e of element.querySelectorAll('.image')) {
									lightbox.insertSlide({
										'href': e.firstChild.href,
										'type': e.firstChild.dataset.type
									});
								}
							}
						} else {
							lightbox.insertSlide({
								'href': element.firstChild.href,
								'type': element.firstChild.dataset.type
							});
						}
					}
				});
				lightbox.reload();
				checkboxes();
				
				return false;
			}
		});
	}
}