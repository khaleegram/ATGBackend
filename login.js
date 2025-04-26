document.getElementById('loginForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const username = document.getElementById('username').value;
    const password = document.getElementById('password').value;
    const role = document.getElementById('role').value;

    if (username && password) {
        // Handle login based on role
        if (role === 'admin') {
            alert('Welcome Admin! Redirecting to the Admin Dashboard...');
            window.location.href = 'admin-dashboard.html'; // Placeholder URL
        } else if (role === 'superadmin') {
            alert('Welcome Super Admin! Redirecting to the Super Admin Dashboard...');
            window.location.href = 'superadmin-dashboard.html'; // Placeholder URL
        } else {
            alert('Invalid role selected!');
        }
    } else {
        alert('Please fill in all fields!');
    }
});
