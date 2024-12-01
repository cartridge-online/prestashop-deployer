# Use the official PrestaShop image as the base image
FROM prestashop/prestashop:8.2.0

# Set environment variables for non-interactive installation
ENV DEBIAN_FRONTEND=noninteractive

# Install Git using apt
RUN apt update && apt install -y git && apt clean

# Set Git default branch to 'main' and mark /var/www/html as a safe directory
RUN git config --global init.defaultBranch main && \
    git config --global --add safe.directory /var/www/html

# Copy local file(s) to /var/www/html
# Replace 'your-local-file' with the actual file name you want to copy
COPY ./deployer /tmp/deployer

RUN chown -R www-data:www-data /tmp/deployer && \
    chmod -R 755 /tmp/deployer

# Expose the default PrestaShop port
EXPOSE 80

# Start the PrestaShop entrypoint script
CMD ["sh", "-c", "cp -r /tmp/deployer /var/www/html/deployer && apache2-foreground"]