// signup.js
document.addEventListener("DOMContentLoaded", function () {
    const roleSelect = document.getElementById("role");
    const accessCodeContainer = document.getElementById("accessCodeContainer");

    roleSelect.addEventListener("change", function () {
        if (this.value === "super-admin") {
            accessCodeContainer.style.display = "block";
        } else {
            accessCodeContainer.style.display = "none";
        }
    });
});
