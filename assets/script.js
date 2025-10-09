// SportAdmin Accordion - Exklusiv accordion (bara en öppen åt gången)
document.addEventListener('DOMContentLoaded', function() {
    const accordions = document.querySelectorAll('[data-sa-accordion]');
    
    accordions.forEach(accordion => {
        const details = accordion.querySelectorAll('details');
        
        details.forEach(detail => {
            detail.addEventListener('toggle', function() {
                if (this.open) {
                    // Stäng alla andra details i samma accordion
                    details.forEach(otherDetail => {
                        if (otherDetail !== this && otherDetail.open) {
                            otherDetail.open = false;
                        }
                    });
                }
            });
        });
    });
});
