import { initializeApp } from "https://www.gstatic.com/firebasejs/10.12.5/firebase-app.js";
import { getAuth } from "https://www.gstatic.com/firebasejs/10.12.5/firebase-auth.js";

const firebaseConfig = {
  apiKey: "AIzaSyDYfwtzKjloXYO0aDj6ioWTf7fSrkl26ME",
  authDomain: "bakery-41ac4.firebaseapp.com",
  projectId: "bakery-41ac4",
  storageBucket: "bakery-41ac4.firebasestorage.app",
  messagingSenderId: "577686166423",
  appId: "1:577686166423:web:1066bebbcb65b5d62d82d2"
};

export const app = initializeApp(firebaseConfig);
export const auth = getAuth(app);
