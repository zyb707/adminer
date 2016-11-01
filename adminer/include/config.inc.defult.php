<?php
/**
	vendor    : 类型
		server: mysql 
		sqlite: SQLite 3 
		sqlite2: SQLite 2 
		pgsql: PostgreSQL
		oracle: Oracle
		mssql: MS SQL
		firebird: Firebird (alpha)
		simpledb: SimpleDB
		mongo: MongoDB (beta)
		elastic: Elasticsearch (beta)
	server   ：host 地址
	username ：用户名
	password ：密 码
	db 		 ：数据库 可不填
*/
$servers = array(
	'mysql'=>array(//server
		'vendor'=>'server',
		'name'=>'默认',//名称
		'server'=>'mysql',//host
		'username'=>'root',
		'password'=>'123456',
		'db'=>'localhost',
	)
);