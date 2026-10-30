// imports

// consts


// functions

function callDectOverwrite(setOn){
	const xhr = new XMLHttpRequest();
	if (setOn==true){
		xhr.open("GET", "/dect_overwrite.php?set=1", true); // true indicates asynchronous
	}else{
		xhr.open("GET", "/dect_overwrite.php?remove=1", true); // true indicates asynchronous
	}
	xhr.onload = () => {
	    if (xhr.status === 200) {
		document.getElementById('dect_overwrite').checked = setOn;
	    } else {
        	console.error(xhr.statusText); // Handle errors
	    }
	};
	xhr.onerror = () => {
	    console.error(xhr.statusText); // Handle network errors
	};
	xhr.send(); // Initiates the request
}

function toggleLamp(setOn){
	const xhr = new XMLHttpRequest();
	var lamp = document.getElementById('lamp_01');
	if (setOn==true){
		xhr.open("GET", "/toggle_shelly.php?id=1", true); // true indicates asynchronous
		lamp.outerHTML = "<image id='lamp_01' href='pics/pic_bulp_on.svg'x='60' y='70' onclick='toggleLamp(false);'/>";
	}else{
		xhr.open("GET", "/toggle_shelly.php?id=1", true); // true indicates asynchronous
		lamp.outerHTML = "<image id='lamp_01' href='pics/pic_bulp_off.svg'x='60' y='70' onclick='toggleLamp(true);'/>";
	}
	xhr.onload = () => {
	    if (xhr.status === 200) {
		console.log('shelly toggled');
	    } else {
        	console.error(xhr.statusText); // Handle errors
	    }
	};
	xhr.onerror = () => {
	    console.error(xhr.statusText); // Handle network errors
	};
	xhr.send(); // Initiates the request
}
