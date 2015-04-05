create table version
(
	version int primary key 
);

create table users
(
	id int primary key auto_increment,
	login text(50) not null,             -- e-mail
	name text,
	pwd text(60),
	is_group bit,
	removeDate datetime default null,

	validated bit default 0,
	token text(60) default null,
  token_type int default null, -- verify, reset password, opt out
	token_valid_till datetime default null,

	failed_logins int not null default 0,
	block_till datetime,

	unique (login(50)), 
	unique (token(60))
);

create table session
(
	id text(50) not null,
	user_id int not null,
	start datetime not null,
	last  datetime not null,
	
	foreign key (user_id) references users(id),
	unique (id(50))
);

create table usergroups
(
	user_id int,
	group_id int,
	primary key (user_id, group_id),
	foreign key (user_id) references users (id),
	foreign key (group_id)references users (id)
);

insert into users (login, pwd, is_group, validated)
	values
	(N'anonymous', null, 0, 1),
	(N'Admins', null, 1, 1),
	(N'root',   N'$2y$10$Pp8UPpeX8PqV01VRWPaYQOFDje.8rJbGKD7tWsulr8G//yZZKKwTG', 0, 1),
  (N'Super', null, 1, 1); -- право работать без ограничений по организациям

insert into usergroups (user_id, group_id)
	values
	(3, 2);


create table logs
(
  id int primary key auto_increment,
  stamp timestamp default CURRENT_TIMESTAMP,
  level text(20) not null,
  user_id int,
  duration int default null,
  operation text(40),
  message text
);



insert into version (version)
values (1);

alter table users AUTO_INCREMENT = 1001