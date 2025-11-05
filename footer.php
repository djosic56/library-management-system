<!-- Footer -->
<!-- Footer fixed-bottom bg-light text-center -->
<footer class="fixed-bottom bg-dark text-white text-center p-0">
	<div class="container py-0">
		<small>Copyright &copy; 2007-2025 <a href="https://www.j-sistem.hr" target="_blank" title="J-SISTEM">j-sistem</a></small>
		<a href="#" class="position-absolute text-white bottom-0 end-0 p-0 px-2">
			<i class="bi bi-arrow-up-circle"></i>
		</a>&nbsp;&nbsp;&nbsp;
	</div>
</footer>


<style>
	/* Ensure body has min-height to push footer down */
	html, body {
		height: 100%;
	}
	
	body {
		display: flex;
		flex-direction: column;
		min-height: 100vh;
	}
	
	.container, .container-fluid {
		flex: 1;
	}
	
	footer {
		margin-top: auto;
	}
	
	footer a:hover {
		opacity: 0.8;
	}
	
	#scrollToTop {
		transition: transform 0.3s;
	}
	
	#scrollToTop:hover {
		transform: translateY(-3px) !important;
	}
</style>

<script>
// Scroll to top functionality
document.addEventListener('DOMContentLoaded', function() {
	const scrollToTopBtn = document.getElementById('scrollToTop');
	
	if (scrollToTopBtn) {
		scrollToTopBtn.addEventListener('click', function(e) {
			e.preventDefault();
			window.scrollTo({
				top: 0,
				behavior: 'smooth'
			});
		});
		
		// Show/hide button based on scroll position
		window.addEventListener('scroll', function() {
			if (window.pageYOffset > 300) {
				scrollToTopBtn.style.opacity = '1';
			} else {
				scrollToTopBtn.style.opacity = '0.5';
			}
		});
	}
});
</script>
