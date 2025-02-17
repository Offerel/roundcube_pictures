/**
 * Roundcube Photos Plugin
 *
 * @version 1.5.9
 * @author Offerel
 * @copyright Copyright (c) 2025, Offerel
 * @license GNU General Public License, version 3
 */
window.onload = function(){
	if( $('#images').length ) {
		$('#images').justifiedGallery({
			rowHeight: 220,
			margins: 7,
			border: 0,
			lastRow: 'nojustify',
			captions: false,
			randomize: false,
			selector: '.glightbox'
		});

		$('#images').justifiedGallery().on('jg.complete', function(e) {
			if(e.currentTarget.clientHeight > 100 && e.currentTarget.clientHeight < document.documentElement.clientWidth) {
				lazyload();
			}
		});
		
		let observer = new IntersectionObserver(function(e) {
			let last = document.getElementById('last') ? false:true;
			if(e[0].isIntersecting && e[0].time > 700 && last) lazyload(true);
		}, {threshold: [0]});
		observer.observe(document.querySelector("#btm"));

		lightbox = GLightbox({
			plyr: {
				config: {
					iconUrl: 'plugins/pictures/js/plyr/plyr.svg',
					muted: true,
				}
			},
			autoplayVideos: false,
			loop: false,
			videosWidth: '100%',
			closeOnOutsideClick: false
		});
	
		lightbox.on('close', () => {
			stop_loop();
			if(document.getElementById('infbtn')) document.getElementById('infbtn').remove();
			document.querySelectorAll('.exinfo').forEach(element => {
				element.classList.remove('eshow');
			});
		});
	
		lightbox.on('slide_changed', (data) => {
			let cindex = data.current.index + 1;
			let cimages = lightbox.elements.length;
			let imglink = new URL(data.current.slideConfig.href);
			let exinfo = 'exif_' + imglink.searchParams.get('p');
			let closebtn = document.querySelector('.gclose');
			let loop_play = (document.getElementById('pbtn')) ? document.getElementById('pbtn').classList.contains('on'):false;

			if(document.getElementById('btn_container')) document.getElementById('btn_container').remove();
			
			let btn_container = document.createElement('div');
			btn_container.id = 'btn_container';
			let gcontainer = document.querySelector('.gcontainer');

			let infobtn = document.createElement('button');
			infobtn.id = 'infbtn';
			infobtn.dataset.iid = exinfo;
			infobtn.addEventListener('click', iBoxShow, true);
			
			let dlbtn = document.createElement('button');
			dlbtn.id = 'dlbtn';
			dlbtn.addEventListener('click', e => {
				window.location = 'plugins/pictures/simg.php?w=4&i=' + new URL(data.current.slideConfig.href).searchParams.get('p');
			});

			let fbtn = document.createElement('button');
			fbtn.id = 'fbtn';
			fbtn.addEventListener('click', e => {
				if(document.fullscreenElement){ 
					document.exitFullscreen();
				} else { 
					document.getElementById('glightbox-body').requestFullscreen();
				}
				fbtn.classList.toggle('on');
			});

			let pbtn = document.createElement('button');
			pbtn.id = 'pbtn';
			if(loop_play) {
				pbtn.classList.add('on');
				pbtn.addEventListener('click', stop_loop);
			} else {
				pbtn.addEventListener('click', loop_slide.bind(this, 5));
			}

			btn_container.appendChild(pbtn);
			if(document.getElementById('images').classList.contains('dl') && data.current.slideConfig.type != 'video') btn_container.appendChild(dlbtn);
			if(document.getElementById(exinfo)) btn_container.appendChild(infobtn);
			btn_container.appendChild(fbtn);
			btn_container.appendChild(closebtn);
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
	
		var prevScrollpos = window.scrollY;
		var header = document.getElementById('header');

		window.onscroll = function() {
			var currentScrollPos = window.scrollY;
			if (prevScrollpos > currentScrollPos) {
				header.style.top = '0';
				(currentScrollPos > 150) ? header.classList.add('shadow'):header.classList.remove('shadow');
			} else {
				header.style.top = '-55px';
				header.classList.remove('shadow');
			}
			prevScrollpos = currentScrollPos;
		}
	}
	
	if (top.location!= self.location) {
		top.location = self.location.href;
	}
	
	window.addEventListener("contextmenu", function(e) {
		e.preventDefault();
		e.stopPropagation();
		return false;
	});
};

function lazyload(slide = false) {
	$.ajax({
		type: 'POST',
		url: window.location.href,
		async: false,
		data: {
			s: $('.glightbox').length
		},
		success: function(response) {
			$('#images').append(response);
			$('#images').justifiedGallery('norewind');
			const html = new DOMParser().parseFromString(response, 'text/html');
			html.body.childNodes.forEach(element => {
				if (element.classList && element.classList.contains('glightbox')) {
					lightbox.insertSlide({
						'href': element.href,
						'type': element.dataset.type
					});
				}
			});
			lightbox.reload();
			return response;
		}
	});
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

function iBoxShow(e) {
	if(!document.getElementById('infbtn')) {
		document.getElementById('infobox').classList.remove('eshow');
		return false;
	}
	
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