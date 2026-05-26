window.igLocationInit = function () {
	const searchEl = document.getElementById('ig_location_search');
	if (!searchEl) {
		return;
	}

	const nameEl = document.getElementById('ig_location_name');
	const latEl = document.getElementById('ig_location_lat');
	const lonEl = document.getElementById('ig_location_lon');
	const placeIdEl = document.getElementById('ig_location_place_id');
	const changedEl = document.getElementById('ig_location_changed');
	const currentEl = document.getElementById('ig_location_current');
	const previewName = document.getElementById('ig_location_preview_name');
	const previewCoords = document.getElementById('ig_location_preview_coords');
	const clearEl = document.getElementById('ig_location_clear');

	const autocomplete = new google.maps.places.Autocomplete(searchEl, {
		fields: ['name', 'geometry', 'place_id'],
	});

	autocomplete.addListener('place_changed', function () {
		const place = autocomplete.getPlace();
		if (!place.geometry) {
			return;
		}

		const lat = place.geometry.location.lat();
		const lon = place.geometry.location.lng();

		latEl.value = lat;
		lonEl.value = lon;
		placeIdEl.value = place.place_id;
		changedEl.value = '1';

		if (!nameEl.value) {
			nameEl.value = place.name;
		}

		previewName.textContent = nameEl.value;
		previewCoords.textContent = lat.toFixed(6) + ', ' + lon.toFixed(6);
		currentEl.style.display = 'block';

		searchEl.value = '';
	});

	nameEl.addEventListener('input', function () {
		previewName.textContent = nameEl.value;
	});

	clearEl.addEventListener('click', function (e) {
		e.preventDefault();
		nameEl.value = '';
		latEl.value = '';
		lonEl.value = '';
		placeIdEl.value = '';
		changedEl.value = '1';
		currentEl.style.display = 'none';
	});
};
