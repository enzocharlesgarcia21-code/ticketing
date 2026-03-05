document.addEventListener('DOMContentLoaded', function() {
    const userBtn = document.querySelector(".admin-user-pill");
    const dropdownMenu = document.querySelector(".admin-dropdown-menu");

    if (userBtn && dropdownMenu) {
        userBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            if (dropdownMenu.style.display === "flex") {
                dropdownMenu.style.display = "none";
            } else {
                dropdownMenu.style.display = "flex";
            }
        });

        document.addEventListener("click", function () {
            dropdownMenu.style.display = "none";
        });

        dropdownMenu.addEventListener("click", function(e) {
            e.stopPropagation();
        });
    }
});
