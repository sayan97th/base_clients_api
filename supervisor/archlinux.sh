sudo cp -f ./base_clients_api_local.conf /etc/supervisor.d/base_clients_api_local.conf
sudo systemctl restart supervisord
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start base_clients_api:*
