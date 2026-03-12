sudo cp -f ./base_clients_api.conf /etc/supervisor/conf.d/base_clients_api.conf
sudo systemctl restart supervisor
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start base_clients_api:*
