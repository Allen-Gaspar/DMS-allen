</main> </div> <script>
window.addEventListener('load', function () {
    const overlay = document.getElementById('page-loading-overlay');
    if (overlay) {
        overlay.classList.add('is-hidden');
        setTimeout(function () {
            overlay.style.display = 'none';
        }, 400);
    }
});
</script>

</body>
</html>