jQuery(document).ready(function($){
    console.log(emedi_vars);
    $('.ld-course-status-action').click(function(e) {
        e.preventDefault(); 
        window.location.href = emedi_vars.pricing_url;
    });
});