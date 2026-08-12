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

